<?php

namespace App\Command;

use App\Service\FindingService;
use App\Service\RetestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:retest', description: 'Retest a single finding using the Playwright browser sidecar.')]
final class RetestCommand extends Command
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly RetestService $retestService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('finding-id', InputArgument::REQUIRED)
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use (chromium or firefox).', 'chromium')
            ->addOption('screenshot', null, InputOption::VALUE_NONE, 'Capture a screenshot for browser retests.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds.', 120000)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be executed without persisting.')
            ->addOption('no-status-update', null, InputOption::VALUE_NONE, 'Do not update finding status.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $finding = $this->findingService->getFindingOrFail((string) $input->getArgument('finding-id'));
        $browser = (string) $input->getOption('browser');
        $screenshot = (bool) $input->getOption('screenshot');
        $timeout = (int) $input->getOption('timeout');
        $dryRun = (bool) $input->getOption('dry-run');
        $noStatusUpdate = (bool) $input->getOption('no-status-update');

        if ($dryRun) {
            $io->note(sprintf(
                'Would retest %s via browser (%s) with timeout %d ms%s',
                $finding->getId(),
                $browser,
                $timeout,
                $screenshot ? ' and screenshot capture' : '',
            ));

            return Command::SUCCESS;
        }

        $run = $this->retestService->retest(
            finding: $finding,
            screenshot: $screenshot,
            timeoutMs: $timeout,
            dryRun: false,
            noStatusUpdate: $noStatusUpdate,
            browser: $browser,
        );

        $io->success(sprintf(
            'Retest %s finished as %s (%s)',
            substr($run->getId(), 0, 8),
            $run->getResult(),
            $run->getMode(),
        ));

        return Command::SUCCESS;
    }
}
