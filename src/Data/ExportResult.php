<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * Data object carrying the flat data needed to render a PDF export.
 */
final readonly class ExportResult
{
    /**
     * @param  string  $title  Human-readable report title.
     * @param  array<string, string>  $columns  Column key → label map.
     * @param  list<array<string, mixed>>  $rows  Flat row arrays.
     * @param  array<string, mixed>  $appliedFilters  Filters echoed back for the header.
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public array $appliedFilters = [],
    ) {}
}
