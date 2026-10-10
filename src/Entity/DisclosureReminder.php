<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'disclosure_reminder')]
class DisclosureReminder
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column(length: 10)]
    public string $dueOn;
    #[ORM\Column(length: 255)]
    public string $nextStep;
    #[ORM\Column(length: 36)]
    public string $revision;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $changedAt;
}
