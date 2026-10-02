<?php

namespace App\Command;

use App\Service\EvidenceStorageInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:artifacts:audit', description: 'Read-only report of missing and unreferenced artifact files. Does not open browsers or delete data.')]
final class ArtifactAuditCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly EvidenceStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $references = $this->connection->fetchFirstColumn(
            'SELECT file_path FROM evidence WHERE file_path IS NOT NULL UNION SELECT screenshot_path FROM retest_run WHERE screenshot_path IS NOT NULL UNION SELECT screenshot_path FROM screenshot_job WHERE screenshot_path IS NOT NULL'
        );
        $known = [];
        $rows = [];
        foreach ($references as $reference) {
            $reference = str_replace('\\', '/', $reference);
            $logical = str_starts_with($reference, 'storage/artifacts/') ? $reference : 'storage/artifacts/'.$reference;
            $known[$logical] = true;
            if (!$this->storage->exists($reference)) {
                $rows[] = ['Missing or unavailable', $reference];
            }
        }
        foreach ($this->storage->listPaths() as $path) {
            if (!isset($known[$path])) {
                $rows[] = ['Unreferenced file', $path];
            }
        }

        $io = new SymfonyStyle($input, $output);
        $io->text('Read-only audit. No files or database records were changed.');
        if ($rows !== []) {
            $io->table(['State', 'Artifact path'], $rows);
            return Command::FAILURE;
        }
        $io->success('All artifact references are available; no unreferenced files found.');
        return Command::SUCCESS;
    }
}
