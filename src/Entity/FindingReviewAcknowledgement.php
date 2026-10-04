<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Immutable manual acknowledgement; it never replaces a finding's judgment. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_review_acknowledgement')]
#[ORM\Index(name: 'idx_review_ack_finding_decision', columns: ['finding_id', 'decision_key', 'reviewed_at'])]
#[ORM\HasLifecycleCallbacks]
class FindingReviewAcknowledgement extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'string', length: 80)]
    private string $decisionKey;

    #[ORM\Column(type: 'string', length: 20)]
    private string $assessment;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $reviewedAt;

    #[ORM\Column(type: 'json')]
    private array $knownObservationStates;

    #[ORM\Column(type: 'json')]
    private array $triggeringObservationIds;

    // Deliberately plain IDs plus snapshots, like assessment history: resetting
    // technical records must not erase the known basis of a human action.
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $observationId;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $evidenceId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $referenceSnapshot;

    public function __construct(Finding $finding, string $decisionKey, string $assessment, array $knownObservationStates, array $triggeringObservationIds, ?string $observationId = null, ?string $evidenceId = null, ?array $referenceSnapshot = null)
    {
        $this->id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->finding = $finding;
        $this->decisionKey = $decisionKey;
        $this->assessment = $assessment;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->knownObservationStates = $knownObservationStates;
        $this->triggeringObservationIds = $triggeringObservationIds;
        $this->observationId = $observationId;
        $this->evidenceId = $evidenceId;
        $this->referenceSnapshot = $referenceSnapshot;
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function getDecisionKey(): string { return $this->decisionKey; }
    public function getAssessment(): string { return $this->assessment; }
    public function getReviewedAt(): \DateTimeImmutable { return $this->reviewedAt; }
    public function getKnownObservationStates(): array { return $this->knownObservationStates; }
    public function getTriggeringObservationIds(): array { return $this->triggeringObservationIds; }
    public function getObservationId(): ?string { return $this->observationId; }
    public function getEvidenceId(): ?string { return $this->evidenceId; }
    public function getReferenceSnapshot(): ?array { return $this->referenceSnapshot; }
}
