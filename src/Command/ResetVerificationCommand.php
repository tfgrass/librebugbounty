<?php

namespace App\Command;

use App\Service\ResetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:reset:verification', description: 'Reset findings to a fresh verification state without deleting the findings themselves.')]
final class ResetVerificationCommand extends Command
{
    public function __construct(
        private readonly ResetService $resetService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually perform the reset.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be reset without mutating anything.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run selected. Use --force to actually reset verification state.');
            return Command::SUCCESS;
        }

        if (!$force) {
            $io->error('Refusing to reset verification state without --force.');
            return Command::FAILURE;
        }

        $result = $this->resetService->resetVerificationState();

        $io->success(sprintf(
            'Verification reset complete. Findings reset: %d, evidence deleted: %d, retest runs deleted: %d, artifact roots cleared: %d',
            $result->findingsReset,
            $result->evidenceDeleted,
            $result->retestRunsDeleted,
            $result->artifactDirectoriesRemoved,
        ));

        return Command::SUCCESS;
    }
}
