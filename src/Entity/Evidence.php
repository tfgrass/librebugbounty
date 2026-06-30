<?php

namespace App\Entity;

use App\Repository\EvidenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvidenceRepository::class)]
#[ORM\Table(name: 'evidence')]
#[ORM\HasLifecycleCallbacks]
class Evidence extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class, inversedBy: 'evidence')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'string', length: 32)]
    private string $kind;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sha256 = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFinding(): Finding
    {
        return $this->finding;
    }

    public function setFinding(Finding $finding): self
    {
        $this->finding = $finding;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function setSha256(?string $sha256): self
    {
        $this->sha256 = $sha256;

        return $this;
    }
}
