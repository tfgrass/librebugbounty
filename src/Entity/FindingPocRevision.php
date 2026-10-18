<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A selected PoC URL is distinct from the immutable source of its evidence. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_poc_revision')]
#[ORM\UniqueConstraint(name: 'uniq_finding_poc_revision', columns: ['finding_id', 'revision'])]
#[ORM\Index(name: 'idx_finding_poc_history', columns: ['finding_id', 'changed_at'])]
class FindingPocRevision
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'integer')]
    private int $revision;

    #[ORM\Column(type: 'text')]
    private string $previousUrl;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'string', length: 16)]
    private string $source;

    // IDs and source snapshots survive deletion of an individual image/job.
    #[ORM\Column(type: 'string', length: 36)]
    private string $evidenceId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $screenshotJobId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceUrl;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $capturedAt;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sha256;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    public function __construct(Finding $finding, int $revision, string $previousUrl, string $url, string $source, Evidence $evidence, ScreenshotJob $job)
    {
        $this->id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->finding = $finding;
        $this->revision = $revision;
        $this->previousUrl = $previousUrl;
        $this->url = $url;
        $this->source = $source;
        $this->evidenceId = $evidence->getId();
        $this->screenshotJobId = $job->getId();
        $metadata = $job->getCaptureMetadata();
        $this->sourceUrl = is_string($metadata['finalUrl'] ?? null) ? $metadata['finalUrl'] : null;
        $this->capturedAt = $job->getCapturedAt();
        $this->sha256 = $evidence->getSha256();
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function getRevision(): int { return $this->revision; }
    public function getPreviousUrl(): string { return $this->previousUrl; }
    public function getUrl(): string { return $this->url; }
    public function getSource(): string { return $this->source; }
    public function getEvidenceId(): string { return $this->evidenceId; }
    public function getScreenshotJobId(): string { return $this->screenshotJobId; }
    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function getCapturedAt(): ?\DateTimeImmutable { return $this->capturedAt; }
    public function getSha256(): ?string { return $this->sha256; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
