<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Service\RetestService;
use App\Service\ValidationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:retest:all', description: 'List or execute browser retests for findings.')]
final class RetestAllCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingRepository $findings,
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
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually run the retests.')
            ->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Browser engine to use (chromium or firefox).', 'chromium')
            ->addOption('screenshot', null, InputOption::VALUE_NONE, 'Capture screenshots for browser retests.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout in milliseconds.', 120000)
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
        $execute = (bool) $input->getOption('execute');
        $browser = (string) $input->getOption('browser');
        $screenshot = (bool) $input->getOption('screenshot');
        $timeout = (int) $input->getOption('timeout');

        $findings = $this->findings->findAllForBrowserRetest($domain, $status, $limit);

        if (!$execute) {
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

        foreach ($findings as $finding) {
            $run = $this->retestService->retest($finding, $screenshot, $timeout, false, false, $browser);
            $io->writeln(sprintf('%s -> %s (%s)', substr($finding->getId(), 0, 8), $run->getResult(), $browser));
        }

        return Command::SUCCESS;
    }
}
