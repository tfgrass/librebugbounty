<?php

namespace App\Command;

use App\Repository\FindingRepository;
use App\Service\ScreenshotQueueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:evidence:check', description: 'Queue browser screenshots for findings that still need them.')]
final class EvidenceCheckCommand extends Command
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly ScreenshotQueueService $screenshotQueue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings.', 20)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $findings = $this->findings->findAllWithoutScreenshotEvidence(null, null, $limit);
        if ($findings === []) {
            $io->success('No findings are currently missing browser screenshots.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Queueing browser screenshots for %d finding(s)...', count($findings)));
        $rows = [];
        foreach ($findings as $index => $finding) {
            $io->writeln(sprintf(
                '[%d/%d] %s %s',
                $index + 1,
                count($findings),
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
            ));

            $result = $this->screenshotQueue->enqueue($finding);
            $io->writeln('  -> '.$result->job->getStatus());

            $rows[] = [
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
                $result->job->getStatus(),
                $result->created ? 'yes' : 'no',
            ];
        }

        $io->table(['id', 'domain', 'jobStatus', 'newlyQueued'], $rows);

        return Command::SUCCESS;
    }
}
