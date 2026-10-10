<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A local, chosen disclosure channel, never a send instruction. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_contact_route')]
class FindingContactRoute
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column(length: 16)]
    public string $channel;
    #[ORM\Column(length: 2048)]
    public string $destination;
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $person = null;
    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $source = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $notes = null;
    #[ORM\Column(type: 'json')]
    public array $provenance;
    #[ORM\Column(length: 36)]
    public string $revision;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $changedAt;
}
