<?php

namespace App\Service;

use App\Dto\DomainUpsertResult;
use App\Entity\Domain;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DomainService
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidationService $validation,
    ) {
    }

    public function upsertDomain(
        string $hostname,
        ?string $scheme = null,
        bool $authorized = false,
        ?string $verificationMethod = null,
        ?string $verificationNote = null,
        ?string $ownerContact = null,
        ?string $notes = null,
    ): DomainUpsertResult {
        $normalizedHostname = $this->validation->normalizeHostname($hostname);
        $existing = $this->domains->findOneByNormalizedHostname($normalizedHostname);
        $created = false;
        $updated = false;

        if ($scheme !== null && $scheme !== '') {
            $this->validation->assertScheme($scheme);
        }

        if ($existing === null) {
            $domain = new Domain();
            $domain->setHostname($normalizedHostname);
            $domain->setScheme($scheme ?? 'https');
            $domain->setAuthorized($authorized);
            $domain->setVerificationMethod($verificationMethod);
            $domain->setVerificationNote($verificationNote);
            $domain->setOwnerContact($ownerContact);
            $domain->setNotes($notes);
            $this->entityManager->persist($domain);
            $created = true;
        } else {
            $domain = $existing;
            if ($scheme !== null && $scheme !== '' && $domain->getScheme() !== $scheme) {
                $domain->setScheme($scheme);
                $updated = true;
            }
            if ($authorized) {
                $domain->setAuthorized(true);
                $updated = true;
            }
            if ($verificationMethod !== null) {
                $domain->setVerificationMethod($verificationMethod);
                $updated = true;
            }
            if ($verificationNote !== null) {
                $domain->setVerificationNote($verificationNote);
                $updated = true;
            }
            if ($ownerContact !== null) {
                $domain->setOwnerContact($ownerContact);
                $updated = true;
            }
            if ($notes !== null) {
                $domain->setNotes($notes);
                $updated = true;
            }
        }

        $this->entityManager->flush();

        return new DomainUpsertResult($domain, $created, $updated);
    }

    public function getDomainOrNull(string $hostname): ?Domain
    {
        return $this->domains->findOneByNormalizedHostname($this->validation->normalizeHostname($hostname));
    }

    public function getDomainOrFail(string $hostname): Domain
    {
        $domain = $this->getDomainOrNull($hostname);
        if ($domain === null) {
            throw new \RuntimeException(sprintf('Domain "%s" does not exist.', $hostname));
        }

        return $domain;
    }
}
