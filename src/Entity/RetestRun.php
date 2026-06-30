<?php

namespace App\Entity;

use App\Repository\RetestRunRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RetestRunRepository::class)]
#[ORM\Table(name: 'retest_run')]
#[ORM\HasLifecycleCallbacks]
class RetestRun extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class, inversedBy: 'retestRuns')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $mode = 'http';

    #[ORM\Column(type: 'string', length: 32)]
    private string $result = 'pending';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $finalUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observedEvidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $screenshotPath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawResult = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->startedAt = new \DateTimeImmutable();
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

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): self
    {
        $this->httpStatus = $httpStatus;

        return $this;
    }

    public function getFinalUrl(): ?string
    {
        return $this->finalUrl;
    }

    public function setFinalUrl(?string $finalUrl): self
    {
        $this->finalUrl = $finalUrl;

        return $this;
    }

    public function getObservedEvidence(): ?string
    {
        return $this->observedEvidence;
    }

    public function setObservedEvidence(?string $observedEvidence): self
    {
        $this->observedEvidence = $observedEvidence;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getScreenshotPath(): ?string
    {
        return $this->screenshotPath;
    }

    public function setScreenshotPath(?string $screenshotPath): self
    {
        $this->screenshotPath = $screenshotPath;

        return $this;
    }

    public function getRawResult(): ?array
    {
        return $this->rawResult;
    }

    public function setRawResult(?array $rawResult): self
    {
        $this->rawResult = $rawResult;

        return $this;
    }
}
