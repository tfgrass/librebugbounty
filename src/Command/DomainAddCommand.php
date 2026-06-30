<?php

namespace App\Command;

use App\Service\DomainService;
use App\Service\ValidationService;
use App\Value\DomainVerificationMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:domain:add', description: 'Add or update a domain in the local database.')]
final class DomainAddCommand extends Command
{
    public function __construct(
        private readonly DomainService $domainService,
        private readonly ValidationService $validation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('hostname', InputArgument::REQUIRED)
            ->addOption('scheme', null, InputOption::VALUE_OPTIONAL, 'Scheme to store with the domain.', null)
            ->addOption('authorized', null, InputOption::VALUE_NONE, 'Mark the domain as authorized.')
            ->addOption('contact', null, InputOption::VALUE_REQUIRED, 'Owner or security contact.')
            ->addOption('verification-method', null, InputOption::VALUE_REQUIRED, 'manual, dns, security_txt, email, other.')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Verification note or free-form note.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hostname = (string) $input->getArgument('hostname');
        $scheme = $input->getOption('scheme');
        $authorized = (bool) $input->getOption('authorized');
        $contact = $input->getOption('contact') ?: null;
        $verificationMethod = $input->getOption('verification-method') ?: null;
        $note = $input->getOption('note') ?: null;

        if ($verificationMethod !== null) {
            $this->validation->assertVerificationMethod($verificationMethod);
        }

        $result = $this->domainService->upsertDomain(
            hostname: $hostname,
            scheme: is_string($scheme) && $scheme !== '' ? $scheme : null,
            authorized: $authorized,
            verificationMethod: $verificationMethod,
            verificationNote: $note,
            ownerContact: $contact,
        );

        $io->success(sprintf(
            '%s domain %s (%s)',
            $result->created ? 'Created' : ($result->updated ? 'Updated' : 'Kept'),
            $result->domain->getHostname(),
            $result->domain->getId(),
        ));

        $io->writeln(sprintf('Authorized: %s', $result->domain->isAuthorized() ? 'yes' : 'no'));
        $io->writeln(sprintf('Scheme: %s', $result->domain->getScheme() ?? 'n/a'));
        $io->writeln(sprintf('Verification method: %s', $result->domain->getVerificationMethod() ?? 'n/a'));

        return Command::SUCCESS;
    }
}
