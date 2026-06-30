<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Service\ValidationService;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:finding:list', description: 'List findings with optional filters.')]
final class FindingListCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingRepository $findings,
        private readonly ValidationService $validation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by finding type.')
            ->addOption('severity', null, InputOption::VALUE_REQUIRED, 'Filter by severity.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domain = null;
        if (is_string($input->getOption('domain')) && $input->getOption('domain') !== '') {
            $domain = $this->domains->findOneByNormalizedHostname($this->validation->normalizeHostname((string) $input->getOption('domain')));
            if ($domain === null) {
                $io->warning('No matching domain was found.');
                return Command::SUCCESS;
            }
        }

        $status = $input->getOption('status') ?: null;
        $type = $input->getOption('type') ?: null;
        $severity = $input->getOption('severity') ?: null;

        if ($status !== null) {
            if (!in_array($status, FindingStatus::values(), true)) {
                throw new \InvalidArgumentException('Invalid --status value.');
            }
        }

        if ($severity !== null) {
            if (!in_array($severity, FindingSeverity::values(), true)) {
                throw new \InvalidArgumentException('Invalid --severity value.');
            }
        }

        $rows = [];
        foreach ($this->findings->findByDomainAndStatus($domain, $status, $type, $severity) as $finding) {
            $rows[] = [
                substr($finding->getId(), 0, 8),
                $finding->getDomain()->getHostname(),
                $finding->getType(),
                $finding->getSeverity(),
                $finding->getStatus(),
                $finding->getTitle(),
                $finding->getSubmittedAt()?->format(DATE_ATOM) ?? 'n/a',
                $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a',
            ];
        }

        $io->table(['id', 'domain', 'type', 'severity', 'status', 'title', 'submittedAt', 'lastRetestedAt'], $rows);

        return Command::SUCCESS;
    }
}
