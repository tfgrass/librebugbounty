<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contact_discovery')]
#[ORM\Index(name: 'idx_contact_discovery_case', columns: ['finding_id', 'fetched_at'])]
class ContactDiscovery
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;
    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Column(length: 64)]
    public string $provider;
    #[ORM\Column(type: 'json')]
    public array $result;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $fetchedAt;
}
