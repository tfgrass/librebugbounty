<?php

namespace App\Dto;

use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\ManualAssessment;
use App\Value\RetestResult;

final readonly class FindingReadFilter
{
    public function __construct(
        public string $domain = '',
        public string $assessment = '',
        public string $observation = '',
        public string $contact = '',
        public string $scope = 'active',
        public string $legacyStatus = '',
        public string $legacyBucket = '',
        public string $type = '',
        public string $severity = '',
        public bool $exactDomain = false,
    ) {
        self::validate('assessment', $assessment, ['', 'unknown', ...ManualAssessment::values()]);
        self::validate('observation', $observation, ['', 'none', ...RetestResult::values()]);
        self::validate('contact', $contact, ['', 'yes', 'no']);
        self::validate('scope', $scope, ['active', 'discarded', 'duplicates', 'all']);
        self::validate('legacy status', $legacyStatus, ['', ...FindingStatus::values()]);
        self::validate('legacy bucket', $legacyBucket, ['', 'open', 'fixed', 'manual_review', 'unchecked']);
        self::validate('severity', $severity, ['', ...FindingSeverity::values()]);
    }

    /** @param list<string> $allowed */
    private static function validate(string $dimension, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported %s filter "%s".', $dimension, $value));
        }
    }
}
