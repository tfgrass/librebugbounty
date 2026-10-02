<?php

namespace App\Command;

use App\Repository\DomainRepository;
use App\Dto\FindingReadFilter;
use App\Repository\FindingReadRepository;
use App\Service\ValidationService;
use App\Value\FindingReadLabels;
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
        private readonly FindingReadRepository $findings,
        private readonly ValidationService $validation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by hostname.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by stored legacy status; does not imply a manual assessment.')
            ->addOption('assessment', null, InputOption::VALUE_REQUIRED, 'Manual assessment: confirmed, fixed, discarded or unknown; discarded requires an archive scope.')
            ->addOption('observation', null, InputOption::VALUE_REQUIRED, 'Latest technical result: still_vulnerable, fixed, inconclusive, error, pending or none.')
            ->addOption('contact', null, InputOption::VALUE_REQUIRED, 'Contact timestamp present: yes or no.')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Scope: active (default), discarded, duplicates or all.')
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

        $status = (string) ($input->getOption('status') ?? '');
        // Preserve explicit legacy archive reads, while ordinary reads ignore them.
        $scope = (string) ($input->getOption('scope') ?? match ($status) {
            'discarded' => 'discarded',
            'duplicate' => 'duplicates',
            default => 'active',
        });
        try {
            $filter = new FindingReadFilter(
                domain: $domain?->getHostname() ?? '',
                assessment: (string) ($input->getOption('assessment') ?? ''),
                observation: (string) ($input->getOption('observation') ?? ''),
                contact: (string) ($input->getOption('contact') ?? ''),
                scope: $scope,
                legacyStatus: $status,
                type: (string) ($input->getOption('type') ?? ''),
                severity: (string) ($input->getOption('severity') ?? ''),
                exactDomain: $domain !== null,
            );
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        }

        $rows = [];
        foreach ($this->findings->findPage($filter, max(1, $this->findings->count($filter))) as $finding) {
            $rows[] = [
                substr($finding->id, 0, 8),
                $finding->domain,
                $finding->type,
                $finding->severity,
                FindingReadLabels::assessment($finding->assessment, $finding->discardReason),
                $finding->assessedAt?->format(DATE_ATOM) ?? 'unbekannt',
                FindingReadLabels::observation($finding->observationResult),
                $finding->observationAt?->format(DATE_ATOM) ?? 'unbekannt',
                $finding->observationMode ?? 'unbekannt',
                FindingReadLabels::contact($finding->contactedAt),
                $finding->contactedAt?->format(DATE_ATOM) ?? 'unbekannt',
                $finding->legacyStatus.' / '.($finding->legacyReviewState ?? 'unbekannt'),
                $finding->title,
                $finding->submittedAt?->format(DATE_ATOM) ?? 'n/a',
            ];
        }

        $io->note('Bewertung, technische Beobachtung und Kontakt sind unabhängig. Altstatus / Review belegt keine frühere manuelle Entscheidung; deren Herkunft kann unklar sein.');
        $io->table(['id', 'domain', 'type', 'severity', 'Bewertung', 'Bewertet am', 'Letzte Beobachtung', 'Beobachtet am', 'Modus', 'Kontakt', 'Kontaktiert am', 'Altstatus / Review', 'title', 'submittedAt'], $rows);

        return Command::SUCCESS;
    }
}
