<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Runs a summary or matrix custom report through RunMatrixReport. A summary
 * is a matrix with no column groupings, so every cell key is `…!T`.
 *
 * For one release a summary also carries its phase-3 shape, built from the
 * fact map: `groupings` (the row grouping descriptors, now `axes.down`),
 * nested `groups` and `grand_total`. Remove them once no client reads them.
 *
 * Record count is COUNT(*) at the grain of the deepest level; "count of
 * accounts" is COUNT(DISTINCT l0.pk). Both use the runner's visibility.
 */
final readonly class SummarizeCustomReport
{
    /** Leaf groups returned at most; the response says when it was cut. */
    public const int MAX_GROUPS = RunMatrixReport::MAX_GROUPS;

    public function __construct(private RunMatrixReport $matrix) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): array
    {
        $result = $this->matrix->handle($type, $validated, $viewer);

        if ($validated->format !== ReportFormat::SUMMARY) {
            return $result;
        }

        /** @var array<string, array<string, mixed>> $cells */
        $cells = $result['fact_map'];
        $empty = ['record_count' => 0, 'counts' => [], 'aggregates' => []];

        return [
            ...$result,
            'groupings' => $result['axes']['down'],
            'grand_total' => $cells[FactMap::TOTAL.'!'.FactMap::TOTAL] ?? $empty,
            'groups' => $this->legacyGroups($result['groupings_down'], $cells, $empty),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $cells
     * @param  array<string, mixed>  $empty
     * @return list<array<string, mixed>>
     */
    private function legacyGroups(array $nodes, array $cells, array $empty): array
    {
        return array_map(fn (array $node): array => [
            'value' => $node['value'],
            ...($cells[$node['key'].'!'.FactMap::TOTAL] ?? $empty),
            'groups' => $this->legacyGroups($node['groups'], $cells, $empty),
        ], $nodes);
    }
}
