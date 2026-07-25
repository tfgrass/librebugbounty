<?php

namespace App\Command;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:export', description: 'Export domains and optional status overviews.')]
final class DomainExportCommand extends Command
{
    public function __construct(
        private readonly DomainRepository $domains,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Export all domains in the database.')
            ->addOption('contacted-only', null, InputOption::VALUE_NONE, 'Only export domains that have at least one contacted finding.')
            ->addOption('overview', null, InputOption::VALUE_NONE, 'Show grouped domains for fixed, contacted, and uncontacted findings.')
            ->addOption('authorized-only', null, InputOption::VALUE_NONE, 'Only include verified domains.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: plain or json.', 'plain')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $exportAll = (bool) $input->getOption('all');
        $contactedOnly = (bool) $input->getOption('contacted-only');
        $overview = (bool) $input->getOption('overview');
        $authorizedOnly = (bool) $input->getOption('authorized-only');
        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['plain', 'json'], true)) {
            throw new \InvalidArgumentException('Unsupported format. Use plain or json.');
        }

        if (($exportAll && $contactedOnly) || ($overview && ($exportAll || $contactedOnly))) {
            throw new \InvalidArgumentException('Use only one of --all, --contacted-only, or --overview.');
        }

        if ($overview) {
            $domains = $this->domains->findAllOrdered($authorizedOnly);
        } elseif ($exportAll) {
            $domains = $this->domains->findAllOrdered($authorizedOnly);
        } elseif ($contactedOnly) {
            $domains = $this->domains->findAllWithContactedFindings($authorizedOnly);
        } else {
            $domains = $this->domains->findAllWithoutContactedOrFixedFindings($authorizedOnly);
        }

        if ($format === 'json') {
            if ($overview) {
                throw new \InvalidArgumentException('Overview output is only available in plain format.');
            }

            $rows = array_map(static fn ($domain): array => [
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'authorized' => $domain->isAuthorized(),
                'findings' => $domain->getFindings()->count(),
            ], $domains);

            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($overview) {
            $this->renderOverview($output, $domains);

            return Command::SUCCESS;
        }

        foreach ($domains as $domain) {
            $output->writeln($domain->getHostname());
        }

        if ($domains === []) {
            $io->success(
                $exportAll ? 'No domains found.' : ($contactedOnly ? 'No contacted domains found.' : 'No non-contacted or fixed domains found.')
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<Domain> $domains
     */
    private function renderOverview(OutputInterface $output, array $domains): void
    {
        $groups = [
            'marked fixed' => [],
            'marked contacted' => [],
            'uncontacted' => [],
        ];

        foreach ($domains as $domain) {
            if ($this->hasFixedFinding($domain)) {
                $groups['marked fixed'][] = $domain;

                continue;
            }

            if ($this->hasContactedFinding($domain)) {
                $groups['marked contacted'][] = $domain;

                continue;
            }

            $groups['uncontacted'][] = $domain;
        }

        foreach ($groups as $title => $groupDomains) {
            $output->writeln(sprintf('== %s (%d) ==', $title, count($groupDomains)));

            if ($groupDomains === []) {
                $output->writeln('(none)');
                $output->writeln('');
                continue;
            }

            foreach ($groupDomains as $domain) {
                $output->writeln($domain->getHostname());
            }

            $output->writeln('');
        }
    }

    private function hasFixedFinding(Domain $domain): bool
    {
        foreach ($domain->getFindings() as $finding) {
            if ($finding->getStatus() === 'fixed') {
                return true;
            }
        }

        return false;
    }

    private function hasContactedFinding(Domain $domain): bool
    {
        foreach ($domain->getFindings() as $finding) {
            if ($finding->getContactedAt() !== null) {
                return true;
            }
        }

        return false;
    }
}
