<?php

namespace App\Entity;

use App\Repository\FindingRepository;
use App\Value\ReviewState;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FindingRepository::class)]
#[ORM\Table(name: 'finding')]
#[ORM\UniqueConstraint(name: 'uniq_finding_domain_url', columns: ['domain_id', 'url'])]
#[ORM\HasLifecycleCallbacks]
class Finding extends AbstractTimestampedEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'findings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 32)]
    private string $type;

    #[ORM\Column(type: 'string', length: 20)]
    private string $severity = 'medium';

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'new';

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'string', length: 10)]
    private string $method = 'GET';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $requestParams = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedEvidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $privateNotes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reportUrl = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reportedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $notifiedOwnerAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRetestedAt = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $reviewState = null;

    /** @var Collection<int, Evidence> */
    #[ORM\OneToMany(mappedBy: 'finding', targetEntity: Evidence::class)]
    private Collection $evidence;

    /** @var Collection<int, RetestRun> */
    #[ORM\OneToMany(mappedBy: 'finding', targetEntity: RetestRun::class)]
    private Collection $retestRuns;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $this->evidence = new ArrayCollection();
        $this->retestRuns = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function setDomain(Domain $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = strtoupper($method);

        return $this;
    }

    public function getRequestParams(): ?array
    {
        return $this->requestParams;
    }

    public function setRequestParams(?array $requestParams): self
    {
        $this->requestParams = $requestParams;

        return $this;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(?string $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getExpectedEvidence(): ?string
    {
        return $this->expectedEvidence;
    }

    public function setExpectedEvidence(?string $expectedEvidence): self
    {
        $this->expectedEvidence = $expectedEvidence;

        return $this;
    }

    public function getPrivateNotes(): ?string
    {
        return $this->privateNotes;
    }

    public function setPrivateNotes(?string $privateNotes): self
    {
        $this->privateNotes = $privateNotes;

        return $this;
    }

    public function getReportUrl(): ?string
    {
        return $this->reportUrl;
    }

    public function setReportUrl(?string $reportUrl): self
    {
        $this->reportUrl = $reportUrl;

        return $this;
    }

    public function getReportedAt(): ?\DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function setReportedAt(?\DateTimeImmutable $reportedAt): self
    {
        $this->reportedAt = $reportedAt;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getNotifiedOwnerAt(): ?\DateTimeImmutable
    {
        return $this->notifiedOwnerAt;
    }

    public function setNotifiedOwnerAt(?\DateTimeImmutable $notifiedOwnerAt): self
    {
        $this->notifiedOwnerAt = $notifiedOwnerAt;

        return $this;
    }

    public function getLastRetestedAt(): ?\DateTimeImmutable
    {
        return $this->lastRetestedAt;
    }

    public function setLastRetestedAt(?\DateTimeImmutable $lastRetestedAt): self
    {
        $this->lastRetestedAt = $lastRetestedAt;

        return $this;
    }

    public function getReviewState(): ?string
    {
        return $this->reviewState;
    }

    public function setReviewState(?string $reviewState): self
    {
        $this->reviewState = $reviewState;

        return $this;
    }

    /** @return Collection<int, Evidence> */
    public function getEvidence(): Collection
    {
        return $this->evidence;
    }

    /** @return Collection<int, RetestRun> */
    public function getRetestRuns(): Collection
    {
        return $this->retestRuns;
    }
}
