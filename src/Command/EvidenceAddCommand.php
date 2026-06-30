<?php

namespace App\Command;

use App\Service\EvidenceService;
use App\Service\FindingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:evidence:add', description: 'Add evidence metadata and optionally copy a file into local storage.')]
final class EvidenceAddCommand extends Command
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly EvidenceService $evidenceService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('finding-id', InputArgument::REQUIRED)
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Evidence kind.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'Evidence value.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a file to copy into evidence storage.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $finding = $this->findingService->getFindingOrFail((string) $input->getArgument('finding-id'));
        $kind = (string) $input->getOption('kind');
        $value = $input->getOption('value') ?: null;
        $file = $input->getOption('file') ?: null;

        if ($kind === '') {
            throw new \InvalidArgumentException('The --kind option is required.');
        }

        $evidence = $this->evidenceService->addEvidence($finding, $kind, $value, $file);

        $io->success(sprintf('Stored evidence %s (%s)', $evidence->getId(), $evidence->getKind()));

        return Command::SUCCESS;
    }
}
