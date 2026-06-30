<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:db:init', description: 'Initialize the local database by running Doctrine migrations.')]
class DatabaseInitCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln('Running Doctrine migrations...');

        $command = sprintf(
            '%s %s doctrine:migrations:migrate -n --allow-no-migration --all-or-nothing',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2).'/bin/console'),
        );

        $exitCode = $this->runMigrations($command);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Database initialization failed with exit code %d.', $exitCode));
        }

        $io->success('Database initialized.');

        return Command::SUCCESS;
    }
    protected function runMigrations(string $command): int
    {
        passthru($command, $exitCode);

        return (int) $exitCode;
    }
}
