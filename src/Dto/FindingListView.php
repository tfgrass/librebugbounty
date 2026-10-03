<?php

namespace App\Dto;

final readonly class FindingListView
{
    /**
     * @param list<FindingReadView> $findings
     * @param array{page: int, pageSize: string, totalFiltered: int, totalPages: int} $pagination
     * @param array<string, array{label: string, count: int, url: string}> $stats
     * @param array{queued: int, running: int, failed: int} $screenshotStats
     * @param array<string, string> $filterQuery
     */
    public function __construct(
        public array $findings,
        public FindingReadFilter $filter,
        public array $pagination,
        public array $stats,
        public array $screenshotStats,
        public array $filterQuery,
        public string $path,
    ) {
    }
}
