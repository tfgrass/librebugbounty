<?php

namespace App\Command;

use App\Repository\FindingRepository;
use App\Service\RetestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:evidence:check', description: 'Run browser checks for findings that still need browser evidence.')]
final class EvidenceCheckCommand extends Command
{
    public function __construct(
        private readonly FindingRepository $findings,
        private readonly RetestService $retestService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings.', 20)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds.', 120000)
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use (chromium or firefox).', 'chromium')
            ->addOption('screenshot', null, InputOption::VALUE_NONE, 'Capture screenshots during browser checks.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $timeout = (int) $input->getOption('timeout');
        $browser = (string) $input->getOption('browser');
        $screenshot = (bool) $input->getOption('screenshot');

        $findings = $this->findings->findOpenFindingsWithoutEvidence($limit);
        if ($findings === []) {
            $io->success('No findings are currently waiting for browser evidence.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Checking %d finding(s) for browser evidence...', count($findings)));
        $rows = [];
        foreach ($findings as $index => $finding) {
            $io->writeln(sprintf(
                '[%d/%d] %s %s',
                $index + 1,
                count($findings),
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
            ));

            $run = $this->retestService->retest(
                finding: $finding,
                screenshot: $screenshot,
                timeoutMs: $timeout,
                browser: $browser,
            );

            $io->writeln(sprintf(
                '  -> %s%s',
                $run->getResult(),
                $run->getScreenshotPath() ? ' with screenshot' : '',
            ));

            $rows[] = [
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
                $run->getResult(),
                $run->getObservedEvidence() ?? 'n/a',
                $run->getErrorMessage() ?? 'n/a',
            ];
        }

        $io->table(['id', 'domain', 'result', 'observedEvidence', 'error'], $rows);

        return Command::SUCCESS;
    }
}
