<?php

namespace App\Value;

/** DNS end label, with local hosts and IP addresses kept outside the TLD chart. */
final class HostnameTld
{
    public static function key(string $hostname): string
    {
        $hostname = rtrim(strtolower($hostname), '.');
        $parts = explode('.', $hostname);
        if (str_contains($hostname, ':') || (count($parts) === 4
            && count(array_filter($parts, static fn (string $part): bool => ctype_digit($part) && strlen($part) <= 3 && (int) $part <= 255)) === 4)) {
            return 'ip';
        }
        if (count($parts) === 1 || in_array(end($parts), ['localhost', 'local'], true)) {
            return 'local';
        }

        return '.'.end($parts);
    }

    /** @return array{string, array<string, string>} */
    public static function sqlCondition(string $key, string $column = 'd.hostname'): array
    {
        // $column is supplied by application code, never by a query parameter.
        $host = 'LOWER(RTRIM('.$column.", '.'))";
        $parts = [];
        $rest = $host;
        for ($index = 0; $index < 3; $index++) {
            $parts[] = 'SUBSTR('.$rest.', 1, INSTR('.$rest.", '.') - 1)";
            $rest = 'SUBSTR('.$rest.', INSTR('.$rest.", '.') + 1)";
        }
        $parts[] = $rest;
        $numericParts = array_map(static fn (string $part): string => '(LENGTH('.$part.') BETWEEN 1 AND 3 AND CAST('.$part.' AS INTEGER) BETWEEN 0 AND 255)', $parts);
        $ip = "(INSTR($host, ':') > 0 OR (LENGTH($host) - LENGTH(REPLACE($host, '.', '')) = 3 AND $host NOT GLOB '*[^0-9.]*' AND ".implode(' AND ', $numericParts).'))';
        $local = "(INSTR($host, '.') = 0 OR $host LIKE '%.localhost' OR $host LIKE '%.local')";

        return match ($key) {
            'ip' => [$ip, []],
            'local' => ['(NOT '.$ip.' AND '.$local.')', []],
            default => ['(NOT '.$ip.' AND NOT '.$local.' AND '.$host.' LIKE :tld)', ['tld' => '%'.$key]],
        };
    }
}
