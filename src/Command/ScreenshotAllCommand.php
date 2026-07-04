<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\EvidenceService;
use App\Service\FindingService;
use App\Service\RetestService;
use App\Service\ValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:screenshot:all', description: 'Generate browser screenshots for findings.')]
final class ScreenshotAllCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingRepository $findings,
        private readonly RetestRunRepository $retestRuns,
        private readonly EvidenceService $evidenceService,
        private readonly FindingService $findingService,
        private readonly RetestService $retestService,
        private readonly ValidationService $validation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Restrict to a hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Restrict to a status.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings.', 1000)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds.', 120000)
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use (chromium or firefox).', 'chromium')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Recreate screenshots even if one already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be executed without persisting.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domain = null;

        if (is_string($input->getOption('domain')) && $input->getOption('domain') !== '') {
            $domain = $this->domains->findOneByNormalizedHostname($this->validation->normalizeHostname((string) $input->getOption('domain')));
            if ($domain === null) {
                throw new \RuntimeException('The provided domain does not exist.');
            }
        }

        $status = is_string($input->getOption('status')) && $input->getOption('status') !== ''
            ? (string) $input->getOption('status')
            : null;
        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = (int) $input->getOption('timeout');
        $browser = (string) $input->getOption('browser');
        $recreate = (bool) $input->getOption('recreate');
        $dryRun = (bool) $input->getOption('dry-run');

        $findings = $recreate
            ? $this->findings->findAllForBrowserRetest($domain, $status, $limit)
            : $this->findings->findAllWithoutScreenshotEvidence($domain, $status, $limit);

        if ($dryRun) {
            $rows = [];
            foreach ($findings as $finding) {
                $rows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getType(),
                    $finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }

            $io->table(['id', 'domain', 'status', 'type', 'submittedAt'], $rows);
            return Command::SUCCESS;
        }

        if ($recreate) {
            $io->writeln('Clearing existing screenshots, evidence, retest runs, and reset state before recreating screenshots...');
            foreach ($findings as $finding) {
                $this->evidenceService->clearEvidence($finding);
                $this->retestRuns->deleteByFinding($finding);
                $this->findingService->resetFreshStartState($finding);
            }
        }

        $io->writeln(sprintf('Generating screenshots for %d finding(s)...', count($findings)));
        foreach ($findings as $index => $finding) {
            $io->writeln(sprintf(
                '[%d/%d] %s %s',
                $index + 1,
                count($findings),
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
            ));

            $run = $this->retestService->retest($finding, true, $timeout, false, false, false, $browser);
            $io->writeln(sprintf(
                '  -> %s%s (%s)',
                $run->getResult(),
                $run->getScreenshotPath() ? ' screenshot saved' : '',
                $browser,
            ));
        }

        return Command::SUCCESS;
    }
}
