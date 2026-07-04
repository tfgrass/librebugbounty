<?php

namespace App\Command;

use App\Entity\Finding;
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

#[AsCommand(name: 'app:evidence:refresh', description: 'Clear evidence, reset findings, and rerun browser verification across selected browsers.')]
final class EvidenceRefreshCommand extends Command
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
            ->addOption('finding-id', null, InputOption::VALUE_REQUIRED, 'Internal worker mode for a single finding.', null)
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Restrict to a hostname.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings.', 1000)
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use (all, chromium, firefox).', 'all')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds.', 120000)
            ->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings to refresh in parallel.', 2)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be executed without persisting.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $findingId = $input->getOption('finding-id');
        $domain = null;

        if (is_string($findingId) && $findingId !== '') {
            $finding = $this->findings->find($findingId);
            if (!$finding instanceof Finding) {
                throw new \RuntimeException(sprintf('Finding "%s" does not exist.', $findingId));
            }

            $browserOption = strtolower(trim((string) $input->getOption('browser')));
            $browsers = $browserOption === 'all' ? ['chromium', 'firefox'] : [$browserOption];
            $timeout = (int) $input->getOption('timeout');

            foreach ($browsers as $browser) {
                if (!in_array($browser, ['chromium', 'firefox'], true)) {
                    throw new \InvalidArgumentException('The --browser option must be all, chromium, or firefox.');
                }
            }

            $this->refreshFinding($finding, $browsers, $timeout, $io, true);
            return Command::SUCCESS;
        }

        if (is_string($input->getOption('domain')) && $input->getOption('domain') !== '') {
            $domain = $this->domains->findOneByNormalizedHostname($this->validation->normalizeHostname((string) $input->getOption('domain')));
            if ($domain === null) {
                throw new \RuntimeException('The provided domain does not exist.');
            }
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = (int) $input->getOption('timeout');
        $browserOption = strtolower(trim((string) $input->getOption('browser')));
        $jobs = max(1, (int) $input->getOption('jobs'));
        $dryRun = (bool) $input->getOption('dry-run');
        $browsers = $browserOption === 'all' ? ['chromium', 'firefox'] : [$browserOption];

        foreach ($browsers as $browser) {
            if (!in_array($browser, ['chromium', 'firefox'], true)) {
                throw new \InvalidArgumentException('The --browser option must be all, chromium, or firefox.');
            }
        }

        $candidates = $this->findings->findAllForBrowserRetest($domain, null, $limit);

        if ($dryRun) {
            $rows = [];
            foreach ($candidates as $finding) {
                $rows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }

            $io->table(['id', 'domain', 'status', 'lastRetestedAt'], $rows);
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Refreshing evidence for %d finding(s) with %d browser run(s) each using up to %d worker(s)...',
            count($candidates),
            count($browsers),
            $jobs,
        ));

        $io->writeln('Clearing existing evidence, screenshots, retest runs, and reset state before rerunning...');
        foreach ($candidates as $finding) {
            $this->clearExistingVerificationData($finding);
        }

        $this->refreshInParallel($candidates, $browsers, $timeout, $jobs, $io);

        return Command::SUCCESS;
    }

    /**
     * @param list<Finding> $findings
     * @param list<string> $browsers
     */
    private function refreshInParallel(array $findings, array $browsers, int $timeout, int $jobs, SymfonyStyle $io): void
    {
        $queue = array_values($findings);
        $active = [];
        $nextIndex = 1;
        $total = count($queue);
        $io->progressStart($total);

        while ($queue !== [] || $active !== []) {
            while ($queue !== [] && count($active) < $jobs) {
                $finding = array_shift($queue);
                if (!$finding instanceof Finding) {
                    continue;
                }

            $worker = $this->spawnWorker($finding, $browsers, $timeout);
            $active[$worker['id']] = [
                'finding' => $finding,
                'process' => $worker['process'],
                    'pipes' => $worker['pipes'],
                    'buffer' => '',
                    'label' => sprintf('[%d/%d] %s %s', $nextIndex, $total, substr($finding->getId(), 0, 8), $finding->getDomain()->getHostname()),
                ];
                $io->writeln(sprintf('%s queued', $active[$worker['id']]['label']));
                $nextIndex++;
            }

            foreach ($active as $id => &$job) {
                foreach ([1, 2] as $pipeIndex) {
                    if (!is_resource($job['pipes'][$pipeIndex])) {
                        continue;
                    }

                    $chunk = stream_get_contents($job['pipes'][$pipeIndex]);
                    if ($chunk !== false && $chunk !== '') {
                        $job['buffer'] .= $chunk;
                    }
                }

                $status = proc_get_status($job['process']);
                if ($status['running']) {
                    continue;
                }

                foreach ($job['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $exitCode = proc_close($job['process']);
                unset($active[$id]);

                if ($job['buffer'] !== '') {
                    $io->write($job['buffer']);
                    if (!str_ends_with($job['buffer'], "\n")) {
                        $io->writeln('');
                    }
                }

                $io->progressAdvance();

                if ($exitCode !== 0) {
                    throw new \RuntimeException(sprintf(
                        'Worker for finding "%s" failed with exit code %d.',
                        $job['finding']->getId(),
                        $exitCode,
                    ));
                }
            }
            unset($job);

            usleep(100000);
        }

        $io->progressFinish();
    }

    /**
     * @param list<string> $browsers
     * @return array{process: resource, pipes: array<int, resource>, id: string}
     */
    private function spawnWorker(Finding $finding, array $browsers, int $timeout): array
    {
        $browserOption = count($browsers) === 2 ? 'all' : $browsers[0];
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/console',
            'app:evidence:refresh',
            '--finding-id='.$finding->getId(),
            '--browser='.$browserOption,
            '--timeout='.$timeout,
            '--no-debug',
            '--env=dev',
        ];

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Failed to start worker for finding "%s".', $finding->getId()));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'id' => $finding->getId(),
        ];
    }

    /**
     * @param list<string> $browsers
     */
    private function refreshFinding(Finding $finding, array $browsers, int $timeout, SymfonyStyle $io, bool $clearFirst = false): void
    {
        $io->writeln(sprintf(
            '[worker] %s %s',
            substr($finding->getId(), 0, 8),
            $finding->getDomain()->getHostname(),
        ));

        if ($clearFirst) {
            $this->clearExistingVerificationData($finding);
        }

        foreach ($browsers as $browser) {
            $run = $this->retestService->retest(
                finding: $finding,
                screenshot: false,
                timeoutMs: $timeout,
                dryRun: false,
                noStatusUpdate: false,
                headless: true,
                browser: $browser,
            );

            $io->writeln(sprintf('  -> %s (%s)', $run->getResult(), $browser));
        }
    }

    private function clearExistingVerificationData(Finding $finding): void
    {
        $this->evidenceService->clearEvidence($finding);
        $this->retestRuns->deleteByFinding($finding);
        $this->findingService->resetFreshStartState($finding);
    }
}
