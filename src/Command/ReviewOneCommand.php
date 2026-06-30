<?php

namespace App\Command;

use App\Service\FindingService;
use App\Service\ReviewService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:review:one', description: 'Review a single finding using the screenshot-first workflow.')]
final class ReviewOneCommand extends Command
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly ReviewService $reviewService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('finding-id', InputArgument::REQUIRED)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds for each browser retest.', 45000)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finding = $this->findingService->getFindingOrFail((string) $input->getArgument('finding-id'));
        $timeout = max(1000, (int) $input->getOption('timeout'));

        $this->reviewService->reviewFinding($finding, $timeout);

        return Command::SUCCESS;
    }
}
