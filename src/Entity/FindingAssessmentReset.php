<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Explicit review-back reset; earlier human actions remain auditable. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_assessment_reset')]
#[ORM\Index(name: 'idx_assessment_reset_finding_time', columns: ['finding_id', 'reset_at'])]
class FindingAssessmentReset
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $resetAt;

    #[ORM\Column(type: 'string', length: 30)]
    private string $source = 'review_back';

    #[ORM\Column(type: 'json')]
    private array $previousState;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $evidenceId;

    public function __construct(Finding $finding, array $previousState, ?string $evidenceId)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->finding = $finding;
        $this->previousState = $previousState;
        $this->evidenceId = $evidenceId;
        $this->resetAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function getResetAt(): \DateTimeImmutable { return $this->resetAt; }
    public function getSource(): string { return $this->source; }
    public function getPreviousState(): array { return $this->previousState; }
    public function getEvidenceId(): ?string { return $this->evidenceId; }
}
