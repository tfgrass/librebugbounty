<?php

namespace App\Command;

use App\Service\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import:txt', description: 'Import a legacy TXT list into the database.')]
final class ImportTxtCommand extends Command
{
    public function __construct(private readonly ImportService $importService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->importService->importTxt((string) $input->getArgument('file'));

        $io->success(sprintf(
            'Import finished. Domains created: %d, findings created: %d, skipped: %d',
            $result->domainsCreated,
            $result->findingsCreated,
            $result->skipped,
        ));

        foreach ($result->errors as $error) {
            $io->warning($error);
        }

        return Command::SUCCESS;
    }
}
