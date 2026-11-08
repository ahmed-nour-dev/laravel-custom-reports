<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * Sorts the groups at one depth of the row groupings by a total instead of
 * by their value (SPEC §14.2). `aggregate` is `a{i}` (the i-th aggregate) or
 * `record_count`.
 */
final readonly class GroupSort
{
    /**
     * @param  'asc'|'desc'  $dir
     */
    public function __construct(
        public int $depth,
        public string $aggregate,
        public string $dir = 'desc',
    ) {}

    /**
     * @return array{depth: int, aggregate: string, dir: 'asc'|'desc'}
     */
    public function toArray(): array
    {
        return ['depth' => $this->depth, 'aggregate' => $this->aggregate, 'dir' => $this->dir];
    }
}
