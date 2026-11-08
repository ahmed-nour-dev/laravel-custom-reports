<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * Keeps only the first `limit` groups of the first row grouping, ranked by a
 * total (SPEC §14.2): `desc` keeps the largest, `asc` the smallest. It
 * narrows the whole report, so every total covers only the groups kept.
 */
final readonly class TopN
{
    public const int MAX_LIMIT = 100;

    /**
     * @param  'asc'|'desc'  $dir
     */
    public function __construct(
        public int $limit,
        public string $aggregate,
        public string $dir = 'desc',
    ) {}

    /**
     * @return array{limit: int, aggregate: string, dir: 'asc'|'desc'}
     */
    public function toArray(): array
    {
        return ['limit' => $this->limit, 'aggregate' => $this->aggregate, 'dir' => $this->dir];
    }
}
