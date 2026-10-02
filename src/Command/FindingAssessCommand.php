<?php

namespace App\Command;

use App\Service\FindingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:finding:assess', description: 'Record an explicit manual assessment or contact timestamp; never invokes a browser.')]
final class FindingAssessCommand extends Command
{
    public function __construct(private readonly FindingService $findings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Finding ID')
            ->addArgument('assessment', InputArgument::REQUIRED, 'confirmed, fixed, discarded, or contacted')
            ->addOption('duplicate', null, InputOption::VALUE_NONE, 'Mark a discarded finding as a duplicate')
            ->addOption('observation-id', null, InputOption::VALUE_REQUIRED, 'Observation explicitly considered for this assessment')
            ->addOption('evidence-id', null, InputOption::VALUE_REQUIRED, 'Evidence explicitly considered for this assessment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $assessment = (string) $input->getArgument('assessment');
            $duplicate = (bool) $input->getOption('duplicate');
            $observation = $input->getOption('observation-id');
            $evidence = $input->getOption('evidence-id');
            if ($assessment === 'contacted' && ($duplicate || $observation !== null || $evidence !== null)) {
                throw new \InvalidArgumentException('Contact only records a timestamp; assessment options do not apply.');
            }
            $finding = $this->findings->getFindingOrFail((string) $input->getArgument('id'));
            if ($assessment === 'contacted') {
                $this->findings->markContacted($finding);
            } else {
                $this->findings->assess($finding, $assessment, $duplicate ? 'duplicate' : null, $observation, $evidence);
            }
            $io->success('Manual action recorded.');

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (\RuntimeException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }
}
