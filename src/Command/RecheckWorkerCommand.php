<?php

namespace App\Command;

use App\Entity\Finding;
use App\Service\RecheckService;
use App\Service\WorkerHeartbeatService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stock recheck worker process. Several instances run in parallel; each one
 * claims the oldest due finding atomically, runs a headless browser retest
 * without a screenshot, and commits each run on its own. Every completed run
 * moves nextDueAt forward and a claim expires after 24 hours, so a stop or
 * crash resumes seamlessly.
 */
#[AsCommand(name: 'app:recheck:worker', description: 'Recheck stock findings older than the cadence, one at a time.')]
final class RecheckWorkerCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private readonly RecheckService $recheckService,
        private readonly ManagerRegistry $doctrine,
        private readonly WorkerHeartbeatService $heartbeats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one due finding and exit.')
            ->addOption('until-empty', null, InputOption::VALUE_NONE, 'Exit as soon as no due finding is claimable instead of idling. Used by app:recheck:catch-up fleets.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Idle polling interval in seconds.', '300')
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Exit after this many rechecks; zero means unlimited.', '0')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Per-finding browser timeout in milliseconds.', '120000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No global lock: several worker processes claim findings atomically
        // in the database (see RecheckService).
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $once = (bool) $input->getOption('once');
        $untilEmpty = (bool) $input->getOption('until-empty');
        if (!$this->waitForSchema($output, $once)) {
            return $once ? Command::FAILURE : Command::SUCCESS;
        }
        $this->heartbeat();

        $sleepMicros = max(1000000, (int) round((float) $input->getOption('sleep') * 1000000));
        $maxJobs = max(0, (int) $input->getOption('max-jobs'));
        $timeout = max(1000, (int) $input->getOption('timeout'));
        $processed = 0;

        while (!$this->stop) {
            $this->heartbeat();
            $outcome = $this->recheckService->processNext($timeout);
            if ($outcome !== null) {
                $processed++;
                $output->writeln(sprintf('%s %s', (new \DateTimeImmutable())->format('H:i:s'), $outcome));
                $manager = $this->doctrine->getManagerForClass(Finding::class);
                if ($manager instanceof EntityManagerInterface) {
                    $manager->clear();
                }
                if ($once || ($maxJobs > 0 && $processed >= $maxJobs)) {
                    break;
                }
                continue;
            }

            if ($once || $untilEmpty) {
                break;
            }
            $this->idle($sleepMicros);
        }

        return Command::SUCCESS;
    }

    private function waitForSchema(OutputInterface $output, bool $once): bool
    {
        $reported = false;
        while (!$this->stop) {
            try {
                $manager = $this->doctrine->getManagerForClass(Finding::class);
                if ($manager instanceof EntityManagerInterface) {
                    $schemaManager = $manager->getConnection()->createSchemaManager();
                    if ($schemaManager->tablesExist(['finding'])
                        && array_key_exists('next_due_at', $schemaManager->listTableColumns('finding'))) {
                        return true;
                    }
                }
            } catch (\Throwable) {
                // Database not reachable yet.
            }
            if ($once) {
                $output->writeln('<error>Recheck schema is not ready.</error>');
                return false;
            }
            if (!$reported) {
                $output->writeln('Waiting for the recheck schema...');
                $reported = true;
            }
            sleep(2);
        }

        return false;
    }

    /**
     * Sleep in short chunks so the shared heartbeat stays fresh and SIGTERM
     * is noticed within seconds even during the default five-minute idle
     * poll interval.
     */
    private function idle(int $sleepMicros): void
    {
        $remaining = $sleepMicros;
        while ($remaining > 0 && !$this->stop) {
            $chunk = min(30_000_000, $remaining);
            usleep($chunk);
            $remaining -= $chunk;
            if ($remaining > 0) {
                $this->heartbeat();
            }
        }
    }

    private function heartbeat(): void
    {
        // Liveness reporting must never take the worker down; a failed
        // heartbeat write is best-effort only.
        try {
            $this->heartbeats->touchIfDue(WorkerHeartbeatService::RECHECK);
        } catch (\Throwable) {
            // Ignore transient database contention.
        }
    }
}
