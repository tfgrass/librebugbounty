<?php

namespace App\Command;

use App\Service\RecheckCatchUpService;
use App\Service\RecheckPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * One-off catch-up run for the stock recheck: pulls every finding whose last
 * check is older than --older-than (default 14 days) due at once, then burns
 * the backlog down with --workers local recheck workers in parallel.
 *
 * The local workers run next to the four supervised DDEV workers and use the
 * same atomic claiming, so a finding is never rechecked twice. Run the
 * command inside the web container (`ddev exec php bin/console
 * app:recheck:catch-up --workers=16`), where the Playwright service is
 * reachable. Without --execute nothing is rescheduled.
 */
#[AsCommand(name: 'app:recheck:catch-up', description: 'Recheck every finding older than a given age with a parallel local worker fleet.')]
final class RecheckCatchUpCommand extends Command
{
    public function __construct(private readonly RecheckCatchUpService $catchUp)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Age threshold such as 14d, 12h, 2w.', '14d')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Number of local parallel recheck workers. Zero only reschedules.', 16)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Per-finding browser timeout in milliseconds.', 120000)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually reschedule the slots. Without this flag the command only previews.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $cutoff = $this->parseAge((string) $input->getOption('older-than'));
        $workerCount = max(0, (int) $input->getOption('workers'));
        $timeout = max(1000, (int) $input->getOption('timeout'));
        $execute = (bool) $input->getOption('execute');

        $preview = $this->catchUp->preview($cutoff, $now);
        $io->definitionList(
            ['Age threshold' => $cutoff->format(DATE_ATOM)],
            ['Stale findings in recheck scope' => (string) $preview['eligible']],
            ['Already due right now' => (string) $preview['alreadyDue']],
            ['Waiting for a future slot' => (string) $preview['waiting']],
            ['Currently claimed by a worker' => (string) $preview['claimed']],
        );

        if (!$execute) {
            $io->note('Dry run. Re-run with --execute to reschedule the waiting slots to now.');
        } elseif ($preview['waiting'] > 0) {
            $pulled = $this->catchUp->pullDue($cutoff, $now);
            $io->success(sprintf('Rescheduled %d finding(s); they are due immediately.', $pulled));
        }

        if ($preview['claimed'] > 0) {
            $io->warning(sprintf(
                '%d finding(s) are leased by a running worker and keep their slot; they are rechecked on their own.',
                $preview['claimed'],
            ));
        }

        if ($workerCount <= 0) {
            return Command::SUCCESS;
        }

        $due = $execute ? $preview['eligible'] : $preview['alreadyDue'];
        if ($due <= 0) {
            $io->success('Nothing due; no workers started.');
            return Command::SUCCESS;
        }

        return $this->runWorkers($io, $output, $workerCount, $timeout);
    }

    private function runWorkers(SymfonyStyle $io, OutputInterface $output, int $workerCount, int $timeout): int
    {
        $console = \dirname(__DIR__, 2).'/bin/console';
        $io->section(sprintf(
            'Starting %d local recheck worker(s). The %d supervised DDEV workers keep running and claim from the same queue.',
            $workerCount,
            4,
        ));
        $io->writeln(sprintf(
            'Watch progress separately with: sqlite3 var/database.sqlite "SELECT COUNT(*) FROM finding WHERE next_due_at <= datetime(\'now\')"',
        ));

        /** @var list<Process> $processes */
        $processes = [];
        for ($i = 1; $i <= $workerCount; $i++) {
            $process = new Process(
                [PHP_BINARY, $console, 'app:recheck:worker', '--until-empty', '--sleep=2', '--timeout='.$timeout, '--no-interaction'],
                \dirname(__DIR__, 2),
            );
            $process->setTimeout(null);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        while ($processes !== []) {
            foreach ($processes as $key => $process) {
                $output->write($process->getIncrementalOutput());
                $output->write($process->getIncrementalErrorOutput());
                if (!$process->isRunning()) {
                    $results[] = $process->getExitCode();
                    unset($processes[$key]);
                }
            }
            if ($processes !== []) {
                usleep(200000);
            }
        }

        $failures = count(array_filter($results, static fn (?int $code) => $code !== 0));
        if ($failures > 0) {
            $io->warning(sprintf('%d of %d worker(s) exited with an error.', $failures, $workerCount));
            return Command::FAILURE;
        }

        $io->success(sprintf('Catch-up finished: all %d workers report an empty queue.', $workerCount));
        return Command::SUCCESS;
    }

    private function parseAge(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^(\d+)([smhdw])$/', strtolower(trim($value)), $matches)) {
            throw new \InvalidArgumentException('The --older-than option must look like 14d, 12h, 15m, or 2w.');
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
