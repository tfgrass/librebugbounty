<?php

namespace App\Command;

use App\Service\ExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:export:json', description: 'Export domains, findings, evidence metadata, and retest runs as JSON.')]
final class ExportJsonCommand extends Command
{
    public function __construct(private readonly ExportService $exportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Restrict to a hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Restrict to a finding status.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->exportService->export(
            $input->getOption('domain') ?: null,
            $input->getOption('status') ?: null,
        );

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
