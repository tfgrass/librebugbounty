<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'disclosure_activity')]
#[ORM\Index(name: 'idx_disclosure_activity_case', columns: ['finding_id', 'occurred_on'])]
class DisclosureActivity
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;
    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column(length: 16)]
    public string $activity;
    #[ORM\Column(length: 10)]
    public string $occurredOn;
    #[ORM\Column(length: 16)]
    public string $channel;
    #[ORM\Column(length: 2048)]
    public string $recipient;
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $ticket = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comment = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $recordedAt;
}
