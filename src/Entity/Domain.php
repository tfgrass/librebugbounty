<?php

namespace App\Entity;

use App\Repository\DomainRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domain')]
#[ORM\UniqueConstraint(name: 'uniq_domain_hostname', columns: ['hostname'])]
#[ORM\HasLifecycleCallbacks]
class Domain extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $hostname;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $scheme = 'https';

    #[ORM\Column(type: 'boolean')]
    private bool $authorized = false;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $verificationMethod = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verificationNote = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $ownerContact = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, Finding> */
    #[ORM\OneToMany(mappedBy: 'domain', targetEntity: Finding::class)]
    private Collection $findings;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->findings = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setHostname(string $hostname): self
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getScheme(): ?string
    {
        return $this->scheme;
    }

    public function setScheme(?string $scheme): self
    {
        $this->scheme = $scheme;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function setAuthorized(bool $authorized): self
    {
        $this->authorized = $authorized;

        return $this;
    }

    public function getVerificationMethod(): ?string
    {
        return $this->verificationMethod;
    }

    public function setVerificationMethod(?string $verificationMethod): self
    {
        $this->verificationMethod = $verificationMethod;

        return $this;
    }

    public function getVerificationNote(): ?string
    {
        return $this->verificationNote;
    }

    public function setVerificationNote(?string $verificationNote): self
    {
        $this->verificationNote = $verificationNote;

        return $this;
    }

    public function getOwnerContact(): ?string
    {
        return $this->ownerContact;
    }

    public function setOwnerContact(?string $ownerContact): self
    {
        $this->ownerContact = $ownerContact;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** @return Collection<int, Finding> */
    public function getFindings(): Collection
    {
        return $this->findings;
    }
}
