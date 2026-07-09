<?php

namespace App\Service;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\ReviewState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class FindingService
{
    public function __construct(
        private readonly DomainService $domainService,
        private readonly FindingRepository $findings,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidationService $validation,
        private readonly Filesystem $filesystem,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    public function createFinding(
        string $url,
        ?string $hostname = null,
        ?string $title = null,
        ?string $type = null,
        string $severity = FindingSeverity::MEDIUM,
        string $method = 'GET',
        ?array $requestParams = null,
        ?string $payload = null,
        ?string $expectedEvidence = 'OPENBUGBOUNTY',
        ?string $privateNotes = null,
        ?string $reportUrl = null,
        ?\DateTimeImmutable $reportedAt = null,
        ?\DateTimeImmutable $submittedAt = null,
        ?\DateTimeImmutable $notifiedOwnerAt = null,
        string $status = FindingStatus::NEW,
        bool $allowUnauthorizedStore = false,
    ): Finding {
        $url = trim($url);
        $this->validation->assertSeverity($severity);
        $this->validation->assertStatus($status);
        $this->validation->assertUrl($url);
        $this->validation->assertHttpMethod($method);

        $parsedUrl = parse_url($url);
        $resolvedHostname = $hostname !== null && $hostname !== ''
            ? $this->validation->normalizeHostname($hostname)
            : (isset($parsedUrl['host']) ? $this->validation->normalizeHostname((string) $parsedUrl['host']) : null);

        if ($resolvedHostname === null) {
            throw new \InvalidArgumentException('Unable to determine the domain hostname from the URL.');
        }

        $scheme = isset($parsedUrl['scheme']) && is_string($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : 'https';
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(sprintf('Unsupported URL scheme "%s".', $scheme));
        }

        $domain = $this->domainService->upsertDomain($resolvedHostname, $scheme, true)->domain;
        $existing = $this->findings->findOneByDomainAndUrl($domain, $url);
        if ($existing instanceof Finding) {
            return $existing;
        }

        $title ??= 'reflected_xss';
        $type ??= 'reflected_xss';
        $submittedAt ??= new \DateTimeImmutable();
        $expectedEvidence = $expectedEvidence !== null && trim($expectedEvidence) !== ''
            ? $expectedEvidence
            : $this->settings?->getDefaultPayload() ?? 'OPENBUGBOUNTY';

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setTitle($title);
        $finding->setType($type);
        $finding->setSeverity($severity);
        $finding->setStatus($status);
        $finding->setUrl($url);
        $finding->setMethod($method);
        $finding->setRequestParams($requestParams);
        $finding->setPayload($payload);
        $finding->setExpectedEvidence($expectedEvidence);
        $finding->setPrivateNotes($privateNotes);
        $finding->setReportUrl($reportUrl);
        $finding->setReportedAt($reportedAt);
        $finding->setSubmittedAt($submittedAt);
        $finding->setNotifiedOwnerAt($notifiedOwnerAt);
        $finding->setReviewState(null);

        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        return $finding;
    }

    public function getFindingOrFail(string $id): Finding
    {
        $finding = $this->findings->find($id);
        if (!$finding instanceof Finding) {
            throw new \RuntimeException(sprintf('Finding "%s" does not exist.', $id));
        }

        return $finding;
    }

    public function deleteFinding(Finding $finding): void
    {
        $this->filesystem->remove(dirname(__DIR__, 2).'/storage/artifacts/'.$finding->getId());
        $this->entityManager->remove($finding);
        $this->entityManager->flush();
    }

    public function markAsOpen(Finding $finding): void
    {
        $finding->setStatus(FindingStatus::NEW);
        $this->entityManager->flush();
    }

    public function markVulnerable(Finding $finding): void
    {
        $finding->setStatus(FindingStatus::VERIFIED);
        $finding->setReviewState(ReviewState::MANUALLY_CHECKED);
        $this->entityManager->flush();
    }

    public function markContacted(Finding $finding): void
    {
        $finding->setContactedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function confirmFixed(Finding $finding): void
    {
        $finding->setStatus(FindingStatus::FIXED);
        $finding->setReviewState(ReviewState::CONFIRMED_FIXED);
        $this->entityManager->flush();
    }

    public function markManualChecking(Finding $finding): void
    {
        $finding->setReviewState(ReviewState::MANUAL_CHECKING);
        $this->entityManager->flush();
    }

    public function resetVerificationState(Finding $finding): void
    {
        $finding->setStatus(FindingStatus::NEW);
        $finding->setLastRetestedAt(null);
        $finding->setReviewState(null);
        $this->entityManager->flush();
    }

    public function resetFreshStartState(Finding $finding): void
    {
        $finding->setStatus(FindingStatus::NEW);
        $finding->setPrivateNotes(null);
        $finding->setReportedAt(null);
        $finding->setNotifiedOwnerAt(null);
        $finding->setContactedAt(null);
        $finding->setLastRetestedAt(null);
        $finding->setReviewState(null);
        $this->entityManager->flush();
    }
}
