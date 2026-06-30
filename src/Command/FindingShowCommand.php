<?php

namespace App\Command;

use App\Repository\EvidenceRepository;
use App\Repository\RetestRunRepository;
use App\Service\FindingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:finding:show', description: 'Show a finding with evidence and retest history.')]
final class FindingShowCommand extends Command
{
    public function __construct(
        private readonly FindingService $findingService,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly RetestRunRepository $retestRuns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('finding-id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $finding = $this->findingService->getFindingOrFail((string) $input->getArgument('finding-id'));

        $io->section('Finding');
        $io->definitionList(
            ['ID' => $finding->getId()],
            ['Domain' => $finding->getDomain()->getHostname()],
            ['Title' => $finding->getTitle()],
            ['Type' => $finding->getType()],
            ['Severity' => $finding->getSeverity()],
            ['Status' => $finding->getStatus()],
            ['URL' => $finding->getUrl()],
            ['Method' => $finding->getMethod()],
            ['Payload' => $finding->getPayload() ?? 'n/a'],
            ['Expected Evidence' => $finding->getExpectedEvidence() ?? 'n/a'],
            ['Private Notes' => $finding->getPrivateNotes() ?? 'n/a'],
            ['Report URL' => $finding->getReportUrl() ?? 'n/a'],
            ['Reported At' => $finding->getReportedAt()?->format(DATE_ATOM) ?? 'n/a'],
            ['Submitted At' => $finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a'],
            ['Notified Owner At' => $finding->getNotifiedOwnerAt()?->format(DATE_ATOM) ?? 'n/a'],
            ['Last Retested At' => $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a'],
        );

        $io->section('Evidence');
        $evidenceRows = [];
        foreach ($this->evidenceRepository->findBy(['finding' => $finding], ['createdAt' => 'DESC']) as $item) {
            $evidenceRows[] = [
                substr($item->getId(), 0, 8),
                $item->getKind(),
                $item->getValue() ?? 'n/a',
                $item->getFilePath() ?? 'n/a',
                $item->getSha256() ?? 'n/a',
                $item->getCreatedAt()->format(DATE_ATOM),
            ];
        }
        $io->table(['id', 'kind', 'value', 'filePath', 'sha256', 'createdAt'], $evidenceRows);

        $io->section('Retest runs');
        $runRows = [];
        foreach ($this->retestRuns->findRecentByFinding($finding, 20) as $run) {
            $runRows[] = [
                substr($run->getId(), 0, 8),
                $run->getMode(),
                $run->getResult(),
                (string) ($run->getHttpStatus() ?? 'n/a'),
                $run->getFinalUrl() ?? 'n/a',
                $run->getObservedEvidence() ?? 'n/a',
                $run->getErrorMessage() ?? 'n/a',
                $run->getStartedAt()->format(DATE_ATOM),
            ];
        }
        $io->table(['id', 'mode', 'result', 'http', 'finalUrl', 'observedEvidence', 'error', 'startedAt'], $runRows);

        return Command::SUCCESS;
    }
}
