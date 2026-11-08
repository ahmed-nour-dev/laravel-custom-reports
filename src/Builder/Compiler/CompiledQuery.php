<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Enums\JoinType;
use Illuminate\Database\Query\Builder;

/**
 * The base query of a report type plus what later compilers need to extend
 * it: the source and effective join type per level, and the lookup joins.
 */
final readonly class CompiledQuery
{
    /**
     * @param  array<int, ReportableSource>  $levels  level => source, l0 first
     * @param  array<int, JoinType>  $joinTypes  effective join per child level (after the cascade)
     * @param  array<int, Edge>  $edges  the child link each child level joins through
     */
    public function __construct(
        public Builder $query,
        public array $levels,
        public array $joinTypes,
        public array $edges,
        public JoinRegistry $joins,
    ) {}

    public function deepestLevel(): int
    {
        return array_key_last($this->levels) ?? 0;
    }
}
