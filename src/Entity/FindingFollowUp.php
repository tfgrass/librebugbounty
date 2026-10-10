<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** Administrative pursuit state; independent of the factual assessment. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_follow_up')]
class FindingFollowUp
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column(length: 16)]
    public string $pursuit = 'active';
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $reason = null;
    #[ORM\Column(type: 'boolean')]
    public bool $contactBlocked = false;
    #[ORM\Column(type: 'boolean')]
    public bool $checksBlocked = false;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $changedAt;
}
