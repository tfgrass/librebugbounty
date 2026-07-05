<?php

namespace App\Command;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\ReviewService;
use App\Service\RetestService;
use App\Service\SettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:review:refresh', description: 'Review pending findings serially and then generate missing screenshots.')]
final class ReviewRefreshCommand extends Command
{
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly FindingRepository $findings,
        private readonly RetestService $retestService,
        private readonly SettingsService $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('review-limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings to review.', PHP_INT_MAX)
            ->addOption('screenshot-limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings to screenshot.', PHP_INT_MAX)
            ->addOption('review-timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in milliseconds for the review phase.')
            ->addOption('screenshot-timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in milliseconds for the screenshot phase.', 120000)
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use for screenshots (chromium or firefox).', 'chromium')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be processed without running browsers.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reviewLimit = max(1, (int) $input->getOption('review-limit'));
        $screenshotLimit = max(1, (int) $input->getOption('screenshot-limit'));
        $reviewTimeout = $input->getOption('review-timeout') !== null
            ? max(1000, (int) $input->getOption('review-timeout'))
            : $this->settings->getReviewScanTimeoutMs();
        $screenshotTimeout = max(1000, (int) $input->getOption('screenshot-timeout'));
        $browser = (string) $input->getOption('browser');
        $dryRun = (bool) $input->getOption('dry-run');

        $reviewTargets = $this->reviewService->getPendingFindings($reviewLimit);
        $screenshotTargets = $this->findings->findAllWithoutScreenshotEvidenceByStatuses(
            null,
            ['verified', 'manual_checking', 'manually_checked'],
            $screenshotLimit,
        );

        if ($dryRun) {
            $io->section('Review targets');
            $reviewRows = [];
            foreach ($reviewTargets as $finding) {
                $reviewRows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getReviewState() ?? 'n/a',
                    $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }
            $io->table(['id', 'domain', 'status', 'reviewState', 'lastRetestedAt'], $reviewRows);

            $io->section('Missing screenshots');
            $screenshotRows = [];
            foreach ($screenshotTargets as $finding) {
                $screenshotRows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getReviewState() ?? 'n/a',
                    $finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }
            $io->table(['id', 'domain', 'status', 'reviewState', 'submittedAt'], $screenshotRows);

            return Command::SUCCESS;
        }

        $this->runReviewPhase($reviewTargets, $reviewTimeout, $output, $io, 'Review phase', true, false);
        $this->runScreenshotPhase($screenshotTargets, $screenshotTimeout, $browser, $output, $io, 'Screenshot phase');

        return Command::SUCCESS;
    }

    /**
     * @param list<Finding> $findings
     */
    private function runReviewPhase(
        array $findings,
        int $timeoutMs,
        OutputInterface $output,
        SymfonyStyle $io,
        string $title,
        bool $captureScreenshots,
        bool $headless,
    ): void
    {
        if ($findings === []) {
            $io->success(sprintf('%s finished. No findings needed attention.', $title));
            return;
        }

        $io->writeln(sprintf(
            '%s: reviewing %d finding(s) serially with %d ms timeout.',
            $title,
            count($findings),
            $timeoutMs,
        ));
        $progressBar = new ProgressBar($output, count($findings));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        foreach ($findings as $finding) {
            $progressBar->setMessage(sprintf('%s %s', substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()));
            $this->reviewService->reviewFinding($finding, $timeoutMs, $captureScreenshots, $headless);
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('%s finished. Processed %d finding(s).', $title, count($findings)));
    }

    /**
     * @param list<Finding> $findings
     */
    private function runScreenshotPhase(array $findings, int $timeoutMs, string $browser, OutputInterface $output, SymfonyStyle $io, string $title): void
    {
        if ($findings === []) {
            $io->success(sprintf('%s finished. No screenshots were missing.', $title));
            return;
        }

        $io->writeln(sprintf(
            '%s: generating screenshots for %d finding(s) with %s.',
            $title,
            count($findings),
            $browser,
        ));
        $progressBar = new ProgressBar($output, count($findings));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        foreach ($findings as $finding) {
            $progressBar->setMessage(sprintf('%s %s', substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()));
            $this->retestService->retest($finding, true, $timeoutMs, false, false, false, $browser);
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('%s finished. Processed %d finding(s).', $title, count($findings)));
    }
}
