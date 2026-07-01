<?php

namespace App\Command;

use App\Service\FindingService;
use App\Service\SettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:finding:add', description: 'Add a finding from a URL.')]
final class FindingAddCommand extends Command
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly SettingsService $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED)
            ->addOption('expected-payload', null, InputOption::VALUE_OPTIONAL, 'Expected payload or marker.')
            ->addOption('annotate', null, InputOption::VALUE_REQUIRED, 'Optional note or annotation.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = (string) $input->getArgument('url');
        $expected = is_string($input->getOption('expected-payload')) && trim((string) $input->getOption('expected-payload')) !== ''
            ? (string) $input->getOption('expected-payload')
            : $this->settings->getDefaultPayload();
        $annotate = $input->getOption('annotate') ?: null;

        $finding = $this->findingService->createFinding(
            url: $url,
            expectedEvidence: $expected,
            privateNotes: $annotate,
        );

        $io->success(sprintf('Stored finding %s for %s', $finding->getId(), $finding->getDomain()->getHostname()));

        return Command::SUCCESS;
    }
}
