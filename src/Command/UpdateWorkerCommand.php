<?php

namespace App\Command;

use App\Service\ReleaseUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:update:worker', description: 'Cache the latest stable project release without changing this installation.')]
final class UpdateWorkerCommand extends Command
{
    private bool $stop = false;

    public function __construct(private readonly ReleaseUpdateService $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Check once if due, then exit; suitable for cron.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Check before the regular due time, respecting the shared one-minute cooldown.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Polling interval in seconds.', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stop = false;
        $once = (bool) $input->getOption('once');
        $force = (bool) $input->getOption('force');
        $sleep = filter_var($input->getOption('sleep'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($sleep === false) {
            $output->writeln('<error>--sleep must be an integer between 1 and 86400 seconds.</error>');

            return Command::INVALID;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        do {
            $result = $this->updates->check($force);
            if ($result['performed'] || $once) {
                $output->writeln(sprintf('Release check: %s%s.', $result['state'], $result['performed'] ? '' : ' (cached)'));
            }
            // --force affects the initial check only, never every loop iteration.
            $force = false;
            if ($once) {
                return $result['state'] === 'error' ? Command::FAILURE : Command::SUCCESS;
            }
            for ($remaining = $sleep; $remaining > 0 && !$this->stop; --$remaining) {
                sleep(1);
            }
        } while (!$this->stop);

        return Command::SUCCESS;
    }
}
