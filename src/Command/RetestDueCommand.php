<?php

namespace App\Command;

use App\Repository\FindingRepository;
use App\Service\RetestService;
use App\Value\FindingStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:retest:due', description: 'List or execute due browser retests.')]
final class RetestDueCommand extends Command
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
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Restrict to a hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Restrict to a status.', FindingStatus::REPORTED)
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Age threshold such as 30d.', '30d')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of findings.', 20)
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
            throw new \RuntimeException('The domain filter is no longer supported in the MVP.');
        }

        $status = (string) $input->getOption('status');
        $olderThan = $this->parseAge((string) $input->getOption('older-than'));
        $limit = max(1, (int) $input->getOption('limit'));
        $execute = (bool) $input->getOption('execute');
        $browser = (string) $input->getOption('browser');
        $screenshot = (bool) $input->getOption('screenshot');
        $timeout = (int) $input->getOption('timeout');

        $findings = $this->findings->findDueForRetest($olderThan, $domain, $status, $limit);

        if (!$execute) {
            $rows = [];
            foreach ($findings as $finding) {
                $rows[] = [
                    substr($finding->getId(), 0, 8),
                    $finding->getDomain()->getHostname(),
                    $finding->getStatus(),
                    $finding->getType(),
                    $finding->getLastRetestedAt()?->format(DATE_ATOM) ?? 'n/a',
                ];
            }

            $io->table(['id', 'domain', 'status', 'type', 'lastRetestedAt'], $rows);
            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $run = $this->retestService->retest($finding, $screenshot, $timeout, false, false, !$screenshot, $browser);
            $io->writeln(sprintf('%s -> %s (%s)', substr($finding->getId(), 0, 8), $run->getResult(), $browser));
        }

        return Command::SUCCESS;
    }

    private function parseAge(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^(\d+)([smhdw])$/', strtolower(trim($value)), $matches)) {
            throw new \InvalidArgumentException('The --older-than option must look like 30d, 12h, 15m, or 7w.');
        }

        return match ($matches[2]) {
            's' => new \DateTimeImmutable(sprintf('-%d seconds', (int) $matches[1])),
            'm' => new \DateTimeImmutable(sprintf('-%d minutes', (int) $matches[1])),
            'h' => new \DateTimeImmutable(sprintf('-%d hours', (int) $matches[1])),
            'd' => new \DateTimeImmutable(sprintf('-%d days', (int) $matches[1])),
            'w' => new \DateTimeImmutable(sprintf('-%d weeks', (int) $matches[1])),
        };
    }
}
