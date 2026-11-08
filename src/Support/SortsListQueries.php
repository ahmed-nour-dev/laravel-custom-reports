<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Applies the sort a list table asks for, restricted to an allowlist.
 *
 * The SPA drives its tables in external mode, so the column the user clicks is
 * sent up as `sort` + `direction` and has to be honoured here — sorting a
 * single page client-side would order an arbitrary slice of the data and
 * present it as the whole set.
 *
 * Anything not in `$sortable` is ignored and the query keeps its own default
 * ordering, so a hand-crafted request cannot sort by an unindexed or private
 * column.
 */
trait SortsListQueries
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $sortable  Columns the client is allowed to sort by
     * @param  'asc'|'desc'  $defaultDirection
     */
    protected function applySort(
        Builder $query,
        array $filters,
        array $sortable,
        string $defaultColumn = 'created_at',
        string $defaultDirection = 'desc',
    ): Builder {
        $column = $filters['sort'] ?? null;

        if (! is_string($column) || ! in_array($column, $sortable, true)) {
            return $query->orderBy($defaultColumn, $defaultDirection);
        }

        $requested = $filters['direction'] ?? null;
        $direction = is_string($requested) && strtolower($requested) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($column, $direction);
    }
}
