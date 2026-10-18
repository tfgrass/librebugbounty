<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Browser identity lives in the Symfony session, never in a submitted ID. */
#[ORM\Entity]
#[ORM\Table(name: 'intake_browser')]
class IntakeBrowser
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $id;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $currentSessionId = null;

    #[ORM\Column(type: 'integer')]
    public int $generation = 0;
}
