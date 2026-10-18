<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Review workflow state, independent from judgments and contact reminders. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_review_disposition')]
#[ORM\Index(name: 'idx_review_recovery_due', columns: ['state', 'retry_state', 'due_at'])]
class ReviewDisposition
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column] public int $revision = 1;
    #[ORM\Column(length: 16)] public string $state = 'deferred';
    #[ORM\Column(length: 32)] public string $reason;
    #[ORM\Column(type: 'text', nullable: true)] public ?string $note = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(type: 'datetime_immutable')] public \DateTimeImmutable $changedAt;
    #[ORM\Column(length: 16, nullable: true)] public ?string $retryState = null;
    #[ORM\Column(length: 64, nullable: true)] public ?string $retryToken = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $retryStartedAt = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $retryFinishedAt = null;
    #[ORM\Column(length: 32, nullable: true)] public ?string $retryResult = null;
    #[ORM\Column(type: 'text', nullable: true)] public ?string $retryMessage = null;
    #[ORM\Column(length: 36, nullable: true)] public ?string $retryRunId = null;
    #[ORM\Column(type: 'json', nullable: true)] public ?array $retryContext = null;
}
