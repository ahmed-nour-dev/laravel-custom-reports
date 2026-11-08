<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * "Has related B" / "has no related B" for an outer level (SPEC §7.2, 5c):
 * `l{N}.pk IS NOT NULL` / `IS NULL`. With rule-5 filters on that level it
 * reads "with / without any *matching* B".
 */
final readonly class RelatedFilter
{
    public function __construct(
        public int $level,
        public bool $has,
    ) {}

    /**
     * @return array{level: int, kind: string}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'kind' => $this->has ? 'with' : 'without'];
    }
}
