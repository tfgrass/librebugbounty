<?php

namespace App\Command;

use App\Entity\ScreenshotJob;
use App\Repository\ScreenshotJobRepository;
use App\Service\ScreenshotOperationLock;
use App\Service\ScreenshotQueueService;
use App\Service\WorkerHeartbeatService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:screenshot:worker', description: 'Process persistent screenshot jobs one at a time.')]
final class ScreenshotWorkerCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private readonly ScreenshotQueueService $queue,
        private readonly ManagerRegistry $doctrine,
        private readonly ScreenshotOperationLock $operationLock,
        private readonly WorkerHeartbeatService $heartbeats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one queued job and exit.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Idle polling interval in seconds.', '2')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Exit after this many jobs; zero means unlimited.', '0')
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'Unique lock suffix when several workers use separate browser sidecars.', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $input->getOption('worker-id'));
        $workerId = trim($workerId === null ? '' : $workerId, '-');
        $workerId = $workerId === '' ? 'default' : $workerId;
        $lock = fopen(sys_get_temp_dir().'/librebugbounty-screenshot-worker-'.$workerId.'.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>Another screenshot worker already owns the queue.</error>');
            return Command::FAILURE;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $once = (bool) $input->getOption('once');
        if (!$this->waitForSchema($output, $once)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return $once ? Command::FAILURE : Command::SUCCESS;
        }
        $this->heartbeat();

        [$failed, $recovered] = $this->operationLock->synchronizedMaintenance(function (): array {
            $jobs = $this->screenshotJobs();
            $failed = $jobs->failRepeatedlyInterrupted();
            $recovered = $jobs->recoverInterrupted();

            return [$failed, $recovered];
        });
        if ($failed > 0) {
            $output->writeln(sprintf('Marked %d repeatedly interrupted screenshot job(s) as failed.', $failed));
        }
        if ($recovered > 0) {
            $output->writeln(sprintf('Requeued %d interrupted screenshot job(s).', $recovered));
        }

        $sleepMicros = max(100000, (int) round((float) $input->getOption('sleep') * 1000000));
        $maxJobs = max(0, (int) $input->getOption('max-jobs'));
        $processed = 0;

        try {
            while (!$this->stop) {
                $this->heartbeat();
                $job = $this->queue->processNext();
                if ($job !== null) {
                    $processed++;
                    $output->writeln(sprintf('%s %s', substr($job->getId(), 0, 8), $job->getStatus()));
                    $manager = $this->doctrine->getManagerForClass(ScreenshotJob::class);
                    if ($manager instanceof EntityManagerInterface) {
                        $manager->clear();
                    }
                    if ($once || ($maxJobs > 0 && $processed >= $maxJobs)) {
                        break;
                    }
                    continue;
                }

                if ($once) {
                    break;
                }
                usleep($sleepMicros);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return Command::SUCCESS;
    }

    private function screenshotJobs(): ScreenshotJobRepository
    {
        $repository = $this->doctrine->getRepository(ScreenshotJob::class);
        if (!$repository instanceof ScreenshotJobRepository) {
            throw new \LogicException('Screenshot job repository is not available.');
        }

        return $repository;
    }

    private function heartbeat(): void
    {
        // Liveness reporting must never take the worker down; a failed
        // heartbeat write is best-effort only.
        try {
            $this->heartbeats->touchIfDue(WorkerHeartbeatService::SCREENSHOT);
        } catch (\Throwable) {
            // Ignore transient database contention.
        }
    }

    private function waitForSchema(OutputInterface $output, bool $once): bool
    {
        $reported = false;
        while (!$this->stop) {
            try {
                $manager = $this->doctrine->getManagerForClass(ScreenshotJob::class);
                if ($manager instanceof EntityManagerInterface
                    && $manager->getConnection()->createSchemaManager()->tablesExist(['screenshot_job'])) {
                    return true;
                }
            } catch (\Throwable) {
                // The database can be briefly unavailable while DDEV starts.
            }

            if ($once) {
                $output->writeln('<error>The screenshot queue schema is not available. Run app:db:init first.</error>');
                return false;
            }
            if (!$reported) {
                $output->writeln('Waiting for the screenshot queue schema...');
                $reported = true;
            }
            usleep(500000);
        }

        return false;
    }
}
