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
            ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in milliseconds for each browser retest.', 45000)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show which findings would be reviewed without running browsers.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = max(1000, (int) $input->getOption('timeout'));

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
            'Resuming review scan with %d finding(s). New findings are processed first. Browser timeout: %d ms.',
            count($targets),
            $timeout,
        ));
        $progressBar = new ProgressBar($output, count($targets));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('new findings first');
        $progressBar->start();

        $processed = 0;
        foreach ($targets as $finding) {
            $progressBar->setMessage(sprintf('%s %s', substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()));
            $this->reviewService->reviewFinding($finding, $timeout);
            $processed++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('Review scan finished. Processed %d finding(s) serially.', $processed));

        return Command::SUCCESS;
    }
}
