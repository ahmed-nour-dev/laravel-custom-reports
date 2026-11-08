<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract that every report definition must implement.
 *
 * Defines the base query, available filters, sortable columns,
 * aggregate summaries, and export column mappings for a report.
 */
interface ReportInterface
{
    /**
     * The base Eloquent query with any eager loads.
     *
     * @return Builder<Model>
     */
    public function baseQuery(): Builder;

    /**
     * Map of filter key → closure that applies the filter to the query.
     *
     * Each closure receives (Builder $query, mixed $value).
     *
     * @return array<string, \Closure>
     */
    public function availableFilters(): array;

    /**
     * List of column names that may be used for sorting.
     *
     * @return list<string>
     */
    public function sortableColumns(): array;

    /**
     * Compute aggregate summaries from the filtered (unpaginated) query.
     *
     * @param  Builder<Model>  $query  The filtered query (clone of baseQuery + filters applied).
     * @return array<string, mixed>
     */
    public function aggregates(Builder $query): array;

    /**
     * Map of column key → human-readable label for export headers.
     *
     * @return array<string, string>
     */
    public function exportColumns(): array;

    /**
     * Transform a single model row into a flat array for export.
     *
     * The keys should match those returned by exportColumns().
     *
     * @return array<string, mixed>
     */
    public function exportRow(Model $model): array;

    /**
     * The default database column used for date range filtering.
     */
    public function dateColumn(): string;

    /**
     * Human-readable title for the report (used in exports/PDFs).
     */
    public function title(): string;
}
