<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Dto\FindingReadFilter;
use App\Repository\FindingReadRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:list', description: 'List stored domains with separate active, manual-assessment and contact counts.')]
final class DomainListCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingReadRepository $findings,
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
            $hostname = $domain->getHostname();

            $rows[] = [
                $hostname,
                $domain->getScheme() ?? 'n/a',
                $domain->isAuthorized() ? 'yes' : 'no',
                (string) $this->findings->count(new FindingReadFilter(domain: $hostname, exactDomain: true)),
                (string) $this->findings->count(new FindingReadFilter(domain: $hostname, assessment: 'confirmed', exactDomain: true)),
                (string) $this->findings->count(new FindingReadFilter(domain: $hostname, assessment: 'fixed', exactDomain: true)),
                (string) $this->findings->count(new FindingReadFilter(domain: $hostname, assessment: 'unknown', exactDomain: true)),
                (string) $this->findings->count(new FindingReadFilter(domain: $hostname, contact: 'yes', exactDomain: true)),
            ];
        }

        $io->table(['hostname', 'scheme', 'authorized', 'active findings', 'manually confirmed', 'manually fixed', 'no recorded manual assessment', 'contacted'], $rows);

        return Command::SUCCESS;
    }
}
