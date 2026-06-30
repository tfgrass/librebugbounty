<?php

namespace App\Command;

use App\Service\ReviewService;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:review:scan', description: 'Run the screenshot-first review scan for findings that still need attention.')]
final class ReviewScanCommand extends Command
{
    public function __construct(
        private readonly ReviewService $reviewService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings to inspect.', PHP_INT_MAX)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds for each browser retest.', 45000)
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Number of findings to review in parallel.', 4)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show which findings would be reviewed without running browsers.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = max(1000, (int) $input->getOption('timeout'));
        $concurrency = max(1, (int) $input->getOption('concurrency'));

        if ((bool) $input->getOption('dry-run')) {
            $rows = [];
            foreach ($this->reviewService->getPendingFindings($limit) as $finding) {
                $rows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getReviewState() ?? 'n/a',
                    $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }

            $io->table(['id', 'domain', 'status', 'reviewState', 'lastRetestedAt'], $rows);
            return Command::SUCCESS;
        }

        $targets = $this->reviewService->getPendingFindings($limit);
        if ($targets === []) {
            $io->success('Review scan finished. No findings needed attention.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Resuming review scan with %d finding(s). New findings are processed first. Browser timeout: %d ms. Concurrency: %d.',
            count($targets),
            $timeout,
            $concurrency,
        ));
        $progressBar = new ProgressBar($output, count($targets));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('new findings first');
        $progressBar->start();

        if ($concurrency === 1) {
            $processed = 0;
            foreach ($targets as $finding) {
                $progressBar->setMessage(sprintf('%s %s', substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()));
                $this->reviewService->reviewFinding($finding, $timeout);
                $processed++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);
            $io->success(sprintf('Review scan finished. Processed %d finding(s).', $processed));

            return Command::SUCCESS;
        }

        $rootDir = dirname(__DIR__, 2);
        $phpBinary = escapeshellarg(PHP_BINARY);
        $running = [];
        $queue = array_values($targets);
        $processed = 0;
        $failed = [];

        $startWorker = function (\App\Entity\Finding $finding) use ($phpBinary, $rootDir, $timeout): array {
            $command = sprintf(
                '%s bin/console app:review:one %s --timeout=%d --no-interaction --no-ansi',
                $phpBinary,
                escapeshellarg($finding->getId()),
                $timeout,
            );
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $process = proc_open($command, $descriptors, $pipes, $rootDir);
            if (!\is_resource($process)) {
                throw new \RuntimeException(sprintf('Failed to start review worker for %s.', $finding->getId()));
            }

            fclose($pipes[0]);

            return [
                'finding' => $finding,
                'process' => $process,
                'stdout' => $pipes[1],
                'stderr' => $pipes[2],
            ];
        };

        $finishWorker = static function (array $worker) use (&$failed): int {
            $stdout = '';
            $stderr = '';
            if (\is_resource($worker['stdout'])) {
                $stdout = (string) stream_get_contents($worker['stdout']);
                fclose($worker['stdout']);
            }
            if (\is_resource($worker['stderr'])) {
                $stderr = (string) stream_get_contents($worker['stderr']);
                fclose($worker['stderr']);
            }

            try {
                $exitCode = \is_resource($worker['process']) ? proc_close($worker['process']) : 0;
            } catch (\Throwable $exception) {
                $exitCode = 1;
                $stderr = trim($stderr."\n".$exception->getMessage());
            }

            if ($exitCode !== 0) {
                $failed[] = [
                    'finding' => $worker['finding'],
                    'stdout' => trim($stdout),
                    'stderr' => trim($stderr),
                    'exitCode' => $exitCode,
                ];
            }

            return $exitCode;
        };

        while ($queue !== [] || $running !== []) {
            while (count($running) < $concurrency && $queue !== []) {
                $finding = array_shift($queue);
                \assert($finding instanceof \App\Entity\Finding);
                $progressBar->setMessage(sprintf('starting %s %s', substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()));
                $running[] = $startWorker($finding);
            }

            $finishedIndexes = [];
            foreach ($running as $index => $worker) {
                if (!\is_resource($worker['process'])) {
                    $finishedIndexes[] = $index;
                    continue;
                }

                try {
                    $status = proc_get_status($worker['process']);
                } catch (\Throwable) {
                    $status = ['running' => false];
                }

                if (($status['running'] ?? false) === true) {
                    continue;
                }

                try {
                    $exitCode = $finishWorker($worker);
                } catch (\Throwable $exception) {
                    $exitCode = 1;
                    $io->warning(sprintf(
                        'Worker crashed for %s (%s): %s',
                        substr($worker['finding']->getId(), 0, 8),
                        $worker['finding']->getDomain()->getHostname(),
                        $exception->getMessage(),
                    ));
                }
                $finishedIndexes[] = $index;
                $processed++;
                $progressBar->setMessage(sprintf('%s %s', substr($worker['finding']->getId(), 0, 8), $worker['finding']->getDomain()->getHostname()));
                $progressBar->advance();

                if ($exitCode !== 0) {
                    $io->warning(sprintf(
                        'Worker failed for %s (%s)',
                        substr($worker['finding']->getId(), 0, 8),
                        $worker['finding']->getDomain()->getHostname(),
                    ));
                }
            }

            if ($finishedIndexes !== []) {
                foreach (array_reverse($finishedIndexes) as $index) {
                    unset($running[$index]);
                }
                $running = array_values($running);
            }

            if ($queue !== [] || $running !== []) {
                usleep(200000);
            }
        }

        $progressBar->finish();
        $io->newLine(2);
        if ($failed !== []) {
            $io->warning(sprintf('Review scan finished. Processed %d finding(s) with %d failure(s).', $processed, count($failed)));
            return Command::FAILURE;
        }

        $io->success(sprintf('Review scan finished. Processed %d finding(s).', $processed));

        return Command::SUCCESS;
    }
}
