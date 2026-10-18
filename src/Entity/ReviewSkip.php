<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Bounded, idempotent skips of one unchanged case context. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_review_skip')]
class ReviewSkip
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;
    #[ORM\Id]
    #[ORM\Column(length: 64)] public string $contextFingerprint;
    #[ORM\Column(type: 'json')] public array $actions = [];
    #[ORM\Column] public int $skipCount = 0;
    #[ORM\Column(type: 'boolean')] public bool $prompted = false;
}
