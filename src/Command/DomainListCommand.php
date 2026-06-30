<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Value\FindingStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:list', description: 'List stored domains and their retest/finding status.')]
final class DomainListCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingRepository $findings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('authorized-only', null, InputOption::VALUE_NONE, 'Only include verified domains.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $authorizedOnly = (bool) $input->getOption('authorized-only');

        $rows = [];
        foreach ($this->domains->findAllOrdered($authorizedOnly) as $domain) {
            $findings = $domain->getFindings();
            $lastRetest = null;
            foreach ($findings as $finding) {
                $retestedAt = $finding->getLastRetestedAt();
                if ($retestedAt !== null && ($lastRetest === null || $retestedAt > $lastRetest)) {
                    $lastRetest = $retestedAt;
                }
            }

            $rows[] = [
                $domain->getHostname(),
                $domain->getScheme() ?? 'n/a',
                $domain->isAuthorized() ? 'yes' : 'no',
                (string) count($findings),
                (string) $this->findings->countOpenFindingsForDomain($domain),
                $lastRetest?->format(DATE_ATOM) ?? 'n/a',
            ];
        }

        $io->table(['hostname', 'scheme', 'authorized', 'findings', 'open findings', 'last retest'], $rows);

        return Command::SUCCESS;
    }
}
