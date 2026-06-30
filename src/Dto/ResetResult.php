<?php

namespace App\Dto;

final class ResetResult
{
    public function __construct(
        public int $findingsReset = 0,
        public int $evidenceDeleted = 0,
        public int $retestRunsDeleted = 0,
        public int $artifactDirectoriesRemoved = 0,
    ) {
    }
}
