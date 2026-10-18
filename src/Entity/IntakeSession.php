<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intake_session')]
#[ORM\Index(name: 'idx_intake_session_browser', columns: ['browser_id', 'started_at'])]
class IntakeSession
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 64)]
    public string $browserId;

    #[ORM\Column(type: 'integer')]
    public int $startedAt;

    #[ORM\Column(type: 'integer')]
    public int $lastActivityAt;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $endedAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $endReason = null;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $continuationOf = null;
}
