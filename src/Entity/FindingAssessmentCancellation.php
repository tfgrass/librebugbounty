<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A separate cancellation preserves the original immutable assessment row. */
#[ORM\Entity]
#[ORM\Table(name: 'finding_assessment_cancellation')]
class FindingAssessmentCancellation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $assessmentId;

    #[ORM\ManyToOne(targetEntity: FindingAssessmentReset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FindingAssessmentReset $reset;

    public function __construct(string $assessmentId, FindingAssessmentReset $reset)
    {
        $this->assessmentId = $assessmentId;
        $this->reset = $reset;
    }
}
