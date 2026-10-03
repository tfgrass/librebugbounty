<?php

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/** Internal list context shared by detail pages and their native forms. */
final class FindingNavigation
{
    public function __construct(private readonly FindingListService $list)
    {
    }

    public function listReturnPath(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || !in_array($parts['path'] ?? null, ['/findings', '/review'], true)
            || array_intersect(['scheme', 'host', 'port', 'user', 'pass', 'fragment'], array_keys($parts)) !== []
        ) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if ($parts['path'] === '/review') {
            return $this->reviewReturnPath($query);
        }
        try {
            [$filter, $page, $pageSize] = $this->list->parse($query);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $normalized = $this->list->filterQuery($filter);
        if (isset($query['pageSize'])) {
            $normalized['pageSize'] = $pageSize;
        }
        if (isset($query['page'])) {
            $normalized['page'] = (string) $page;
        }

        return '/findings'.($normalized === ['scope' => 'active'] && !isset($parts['query'])
            ? '' : '?'.http_build_query($normalized, '', '&', PHP_QUERY_RFC3986));
    }

    /** @param array<string, mixed> $query */
    private function reviewReturnPath(array $query): ?string
    {
        $kind = $query['kind'] ?? 'all';
        $images = $query['images'] ?? 'ready';
        $after = $query['after'] ?? '';
        $evidence = $query['evidence'] ?? '';
        if (!is_string($kind) || !in_array($kind, ['all', 'changed', 'inconclusive', 'error', 'unchecked'], true)
            || !is_string($images) || !in_array($images, ['ready', 'all', 'missing'], true)
            || !is_string($after) || ($after !== '' && !Uuid::isValid($after))
            || !is_string($evidence) || ($evidence !== '' && !Uuid::isValid($evidence))
        ) {
            return null;
        }
        $normalized = [];
        if ($kind !== 'all') {
            $normalized['kind'] = $kind;
        }
        if ($images !== 'ready') {
            $normalized['images'] = $images;
        }
        if ($after !== '') {
            $normalized['after'] = $after;
        }
        if ($evidence !== '') {
            $normalized['evidence'] = $evidence;
        }

        return '/review'.($normalized === [] ? '' : '?'.http_build_query($normalized, '', '&', PHP_QUERY_RFC3986));
    }

    /** @param array<string, mixed> $parameters */
    public function findingReturnPath(string $id, array $parameters): string
    {
        if (($parameters['surface'] ?? null) !== 'studio') {
            return '/legacy/findings/'.rawurlencode($id);
        }
        $path = '/findings/'.rawurlencode($id);
        $returnTo = $this->listReturnPath($parameters['return_to'] ?? null);

        return $path.($returnTo === null ? '' : '?'.http_build_query(['return_to' => $returnTo], '', '&', PHP_QUERY_RFC3986));
    }
}
