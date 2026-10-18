<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Confirmed new browser intake; its effort survives later finding deletion. */
#[ORM\Entity]
#[ORM\Table(name: 'intake_session_entry')]
#[ORM\Index(name: 'idx_intake_entry_session', columns: ['session_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_intake_entry_browser', columns: ['browser_id', 'recorded_at'])]
class IntakeSessionEntry
{
    // A finding can contribute to intake progress exactly once. Plain snapshot
    // IDs deliberately keep activity intact when the finding is later removed.
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $findingId;

    #[ORM\Column(length: 36)]
    public string $sessionId;

    #[ORM\Column(length: 64)]
    public string $browserId;

    #[ORM\Column(type: 'integer')]
    public int $recordedAt;
}
