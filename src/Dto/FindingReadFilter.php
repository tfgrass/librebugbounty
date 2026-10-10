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
        public string $q = '',
        public string $event = '',
        public string $from = '',
        public string $to = '',
        public string $tld = '',
        public string $sent = '',
        public string $legacyReview = '',
        public string $pursuit = '',
        public string $closureReason = '',
        public string $contactWork = '',
    ) {
        self::validate('contact work', $contactWork, ['', 'allowed', 'blocked']);
        self::validate('pursuit', $pursuit, ['', 'active', 'closed']);
        self::validate('closure reason', $closureReason, ['', ...array_keys(\App\Value\PursuitStatus::REASONS)]);
        self::validate('assessment', $assessment, ['', 'unknown', ...ManualAssessment::values()]);
        self::validate('observation', $observation, ['', 'none', ...RetestResult::values()]);
        self::validate('contact', $contact, ['', 'yes', 'no']);
        self::validate('sent', $sent, ['', 'yes', 'no']);
        self::validate('scope', $scope, ['active', 'discarded', 'duplicates', 'all']);
        self::validate('legacy status', $legacyStatus, ['', ...FindingStatus::values()]);
        self::validate('legacy bucket', $legacyBucket, ['', 'open', 'fixed', 'manual_review', 'unchecked']);
        self::validate('legacy review', $legacyReview, ['', 'confirmed_fixed', 'manually_checked']);
        self::validate('severity', $severity, ['', ...FindingSeverity::values()]);
        self::validate('event', $event, ['', 'reported', 'sent', 'contacted', 'confirmed', 'fixed']);
        foreach ([$from, $to] as $date) {
            if ($date !== '') {
                self::date($date);
            }
        }
        if (($from !== '' || $to !== '') && $event === '') {
            throw new \InvalidArgumentException('Ein Datumsfilter benötigt ein Ereignis.');
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new \InvalidArgumentException('Der Beginn liegt nach dem Ende des Zeitraums.');
        }
        if ($tld !== '' && !in_array($tld, ['ip', 'local'], true) && !preg_match('/^\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $tld)) {
            throw new \InvalidArgumentException('Ungültige TLD.');
        }
    }

    public static function date(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || (int) substr($value, 0, 4) < 1 || (int) substr($value, 0, 4) > 9998) {
            throw new \InvalidArgumentException('Datumsangaben müssen gültige Tage im Format JJJJ-MM-TT sein.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Berlin'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datumsangaben müssen gültige Tage im Format JJJJ-MM-TT sein.');
        }

        return $date;
    }

    /** @param list<string> $allowed */
    private static function validate(string $dimension, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Ungültiger Filterwert.');
        }
    }
}
