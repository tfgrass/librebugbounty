<?php

declare(strict_types=1);

namespace App\Dto;

/** Calendar ranges are Berlin dates; stored timestamps retain their runtime zone. */
final readonly class StatisticsPeriod
{
    public function __construct(
        public string $kind,
        public string $granularity,
        public \DateTimeImmutable $anchor,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
        public \DateTimeImmutable $now,
        public ?\DateTimeImmutable $previousFrom,
        public ?\DateTimeImmutable $previousUntil,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, \DateTimeImmutable $now, ?\DateTimeImmutable $firstActivity = null): self
    {
        foreach (['period', 'anchor', 'from', 'to', 'granularity'] as $field) {
            if (array_key_exists($field, $query) && !is_string($query[$field])) {
                throw new \InvalidArgumentException('Statistikfilter müssen einzelne Textwerte sein.');
            }
        }
        $kind = $query['period'] ?? 'month';
        $granularity = $query['granularity'] ?? 'day';
        if (!in_array($kind, ['week', 'month', 'year', 'all', 'custom'], true)) {
            throw new \InvalidArgumentException('Unbekannter Statistikzeitraum.');
        }
        if (!in_array($granularity, ['day', 'week', 'month'], true)) {
            throw new \InvalidArgumentException('Unbekannte zeitliche Auflösung.');
        }
        $zone = new \DateTimeZone('Europe/Berlin');
        $now = $now->setTimezone($zone);
        $anchor = isset($query['anchor']) ? self::date($query['anchor']) : $now->setTime(0, 0);
        // Validate every supplied date, including dates currently hidden by the form.
        $customFrom = isset($query['from']) && $query['from'] !== '' ? self::date($query['from']) : null;
        $customTo = isset($query['to']) && $query['to'] !== '' ? self::date($query['to']) : null;
        if ($kind === 'custom') {
            if ($customFrom === null || $customTo === null || $customTo < $customFrom) {
                throw new \InvalidArgumentException('Eigene Zeiträume benötigen gültige Anfangs- und Enddaten in der richtigen Reihenfolge.');
            }
            $from = $customFrom;
            $until = $customTo->modify('+1 day');
            $days = (int) $from->diff($until)->days;
            $previousFrom = $from->modify('-'.$days.' days');
            $previousUntil = $from;
            $anchor = $from;
        } elseif ($kind === 'all') {
            $from = ($firstActivity ?? $now)->setTimezone($zone)->setTime(0, 0);
            // Keep future-only or empty data from producing an inverted range.
            $from = min($from, $now->setTime(0, 0));
            $until = $now->setTime(0, 0)->modify('+1 day');
            $previousFrom = $previousUntil = null;
        } else {
            $from = match ($kind) {
                'week' => $anchor->modify('-'.((int) $anchor->format('N') - 1).' days'),
                'month' => $anchor->modify('first day of this month'),
                'year' => $anchor->setDate((int) $anchor->format('Y'), 1, 1),
            };
            $until = $from->modify(match ($kind) { 'week' => '+1 week', 'month' => '+1 month', 'year' => '+1 year' });
            $previousFrom = $from->modify(match ($kind) { 'week' => '-1 week', 'month' => '-1 month', 'year' => '-1 year' });
            $previousUntil = $from;
        }

        // Every visible range must also be valid for the destination case list.
        self::date($from->format('Y-m-d'));
        self::date($until->modify('-1 day')->format('Y-m-d'));

        return new self($kind, $granularity, $anchor, $from, $until, $now, $previousFrom, $previousUntil);
    }

    public static function date(string $value): \DateTimeImmutable
    {
        return FindingReadFilter::date($value);
    }

    public function ongoing(): bool
    {
        return $this->now >= $this->from && $this->now < $this->until;
    }

    /** Equal elapsed durations also handle shorter previous months/leap years. */
    public function comparison(): ?array
    {
        if ($this->previousFrom === null || $this->previousUntil === null || $this->now < $this->from) {
            return null;
        }
        if (!$this->ongoing()) {
            return [$this->from, $this->until, $this->previousFrom, $this->previousUntil];
        }
        $elapsed = min(
            $this->now->getTimestamp() - $this->from->getTimestamp(),
            $this->previousUntil->getTimestamp() - $this->previousFrom->getTimestamp(),
        );

        return [
            $this->from, $this->from->setTimestamp($this->from->getTimestamp() + $elapsed),
            $this->previousFrom, $this->previousFrom->setTimestamp($this->previousFrom->getTimestamp() + $elapsed),
        ];
    }

    public function navigationUrl(bool $next): ?string
    {
        if ($this->kind === 'all') {
            return null;
        }
        $direction = $next ? '+' : '-';
        if ($this->kind === 'custom') {
            $days = (int) $this->from->diff($this->until)->days;
            $shift = $direction.$days.' days';
            $nextFrom = $this->from->modify($shift);
            $nextTo = $this->until->modify('-1 day')->modify($shift);
            if ((int) $nextFrom->format('Y') < 1 || (int) $nextTo->format('Y') > 9998) {
                return null;
            }
            $query = ['period' => 'custom', 'from' => $nextFrom->format('Y-m-d'), 'to' => $nextTo->format('Y-m-d')];
        } else {
            $shift = $direction.'1 '.$this->kind;
            $nextAnchor = $this->from->modify($shift);
            if ((int) $nextAnchor->format('Y') < 1 || (int) $nextAnchor->format('Y') > 9998) {
                return null;
            }
            $query = ['period' => $this->kind, 'anchor' => $nextAnchor->format('Y-m-d')];
        }
        $query['granularity'] = $this->granularity;

        return '/statistics?'.http_build_query($query);
    }
}
