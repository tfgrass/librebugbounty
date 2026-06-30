<?php

namespace App\Dto;

final class RetestResultData
{
    public function __construct(
        public readonly string $result,
        public readonly ?int $httpStatus = null,
        public readonly ?string $finalUrl = null,
        public readonly ?string $observedEvidence = null,
        public readonly ?string $dialogText = null,
        public readonly ?string $screenshotBase64 = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {
    }
}
