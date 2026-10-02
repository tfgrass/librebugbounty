<?php

namespace App\Entity;

use App\Repository\ScreenshotJobRepository;
use App\Value\ScreenshotJobStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScreenshotJobRepository::class)]
#[ORM\Table(name: 'screenshot_job')]
#[ORM\Index(name: 'idx_screenshot_job_status_requested', columns: ['status', 'requested_at'])]
#[ORM\HasLifecycleCallbacks]
class ScreenshotJob extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = ScreenshotJobStatus::QUEUED;

    /** Equal to the finding ID while queued/running; NULL for terminal jobs. */
    #[ORM\Column(type: 'string', length: 36, nullable: true, unique: true)]
    private ?string $activeKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $capturedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $screenshotPath = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $captureMetadata = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function setFinding(Finding $finding): self { $this->finding = $finding; return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getActiveKey(): ?string { return $this->activeKey; }
    public function setActiveKey(?string $activeKey): self { $this->activeKey = $activeKey; return $this; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function setRequestedAt(\DateTimeImmutable $requestedAt): self { $this->requestedAt = $requestedAt; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }
    public function getCapturedAt(): ?\DateTimeImmutable { return $this->capturedAt; }
    public function setCapturedAt(?\DateTimeImmutable $capturedAt): self { $this->capturedAt = $capturedAt; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self { $this->finishedAt = $finishedAt; return $this; }
    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $attempts): self { $this->attempts = $attempts; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }
    public function getScreenshotPath(): ?string { return $this->screenshotPath; }
    public function setScreenshotPath(?string $screenshotPath): self { $this->screenshotPath = $screenshotPath; return $this; }
    public function getCaptureMetadata(): ?array { return $this->captureMetadata; }
    public function setCaptureMetadata(?array $captureMetadata): self { $this->captureMetadata = $captureMetadata; return $this; }
}
