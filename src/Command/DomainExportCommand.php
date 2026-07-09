<?php

namespace App\Command;

use App\Repository\DomainRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:export', description: 'Export domains that have not been contacted yet.')]
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
            ->addOption('authorized-only', null, InputOption::VALUE_NONE, 'Only include verified domains.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: plain or json.', 'plain')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $authorizedOnly = (bool) $input->getOption('authorized-only');
        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['plain', 'json'], true)) {
            throw new \InvalidArgumentException('Unsupported format. Use plain or json.');
        }

        $domains = $this->domains->findAllWithoutContactedFindings($authorizedOnly);

        if ($format === 'json') {
            $rows = array_map(static fn ($domain): array => [
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'authorized' => $domain->isAuthorized(),
                'findings' => $domain->getFindings()->count(),
            ], $domains);

            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        foreach ($domains as $domain) {
            $output->writeln($domain->getHostname());
        }

        if ($domains === []) {
            $io->success('No non-contacted domains found.');
        }

        return Command::SUCCESS;
    }
}
