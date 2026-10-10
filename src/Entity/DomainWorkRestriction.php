<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** Exact hostname policy survives deletion/reimport of individual cases. */
#[ORM\Entity]
#[ORM\Table(name: 'domain_work_restriction')]
class DomainWorkRestriction
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    public string $hostname;
    #[ORM\Column(type: 'boolean')]
    public bool $contactBlocked = false;
    #[ORM\Column(type: 'boolean')]
    public bool $checksBlocked = false;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $changedAt;
}
