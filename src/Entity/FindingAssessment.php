<?php

namespace App\Entity;

use App\Repository\FindingAssessmentRepository;
use App\Value\ManualAssessment;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FindingAssessmentRepository::class)]
#[ORM\Table(name: 'finding_assessment')]
#[ORM\Index(name: 'idx_finding_assessment_finding_time', columns: ['finding_id', 'assessed_at'])]
#[ORM\HasLifecycleCallbacks]
class FindingAssessment extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'string', length: 20)]
    private string $assessment;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $discardReason;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $assessedAt;

    #[ORM\Column(type: 'string', length: 20)]
    private string $source = 'manual';

    // Keep IDs and a snapshot, rather than foreign keys that could erase the
    // known basis of a decision when an observation or evidence is deleted.
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $observationId;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $evidenceId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $referenceSnapshot;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $knownObservationIds;

    // Written conditionally in the same assessment transaction so deployed v1
    // can continue creating judgments while the additive migration is prepared.
    #[ORM\Column(type: 'json', nullable: true, insertable: false, updatable: false)]
    private ?array $knownObservationStates = null;

    public function __construct(
        Finding $finding,
        string $assessment,
        ?string $discardReason,
        \DateTimeImmutable $assessedAt,
        ?string $observationId = null,
        ?string $evidenceId = null,
        ?array $referenceSnapshot = null,
        array $knownObservationIds = [],
        ?array $knownObservationStates = null,
    ) {
        ManualAssessment::validate($assessment, $discardReason);
        $this->id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->finding = $finding;
        $this->assessment = $assessment;
        $this->discardReason = $discardReason;
        $this->assessedAt = $assessedAt;
        $this->observationId = $observationId;
        $this->evidenceId = $evidenceId;
        $this->referenceSnapshot = $referenceSnapshot;
        $this->knownObservationIds = $knownObservationIds;
        $this->knownObservationStates = $knownObservationStates;
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function getAssessment(): string { return $this->assessment; }
    public function getDiscardReason(): ?string { return $this->discardReason; }
    public function getAssessedAt(): \DateTimeImmutable { return $this->assessedAt; }
    public function getSource(): string { return $this->source; }
    public function getObservationId(): ?string { return $this->observationId; }
    public function getEvidenceId(): ?string { return $this->evidenceId; }
    public function getReferenceSnapshot(): ?array { return $this->referenceSnapshot; }
    /** @return list<string> */
    public function getKnownObservationIds(): array { return $this->knownObservationIds; }
    public function getKnownObservationStates(): ?array { return $this->knownObservationStates; }
}
