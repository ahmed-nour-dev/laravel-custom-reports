<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder;

use AhmedNour\CustomReports\Data\ChartSpec;
use AhmedNour\CustomReports\Data\ReportDefinition;

/**
 * Reads a chart's numbers out of a fact map (SPEC §14.5), so every surface —
 * the builder, a saved report, a background run, the dashboard widget —
 * gets the same small payload instead of the whole map:
 *
 * - `categories`: the first row grouping's groups, in report order (or,
 *   on a matrix grouped by columns only, the first column grouping's);
 * - `series`: the groups of the second row grouping (`g1`) or the first
 *   column grouping (`x0`), or one unnamed series;
 * - `data[series][category]`: the chosen value per cell (null when empty);
 * - `total`: the grand total — all a metric or gauge needs.
 *
 * Groups go out as `{value, label}`, with their grouping's description, so
 * the SPA labels them the way it labels the table (translated picklists,
 * date groupings, buckets).
 */
final class ChartData
{
    /**
     * @param  array{down: list<array<string, mixed>>, across: list<array<string, mixed>>}  $axes
     * @param  list<array<string, mixed>>  $down  groupings_down tree
     * @param  list<array<string, mixed>>  $across  groupings_across tree
     * @param  array<string, array<string, mixed>>  $cells  fact_map
     * @param  list<array<string, mixed>>  $aggregates  described aggregates (`column` = a{i})
     * @return array<string, mixed>
     */
    public function build(ChartSpec $chart, array $axes, array $down, array $across, array $cells, array $aggregates): array
    {
        $value = static function (?array $cell) use ($chart): mixed {
            if ($cell === null) {
                return null;
            }

            return $chart->value === ReportDefinition::RECORD_COUNT ? ($cell['record_count'] ?? 0) : ($cell['aggregates'][$chart->value] ?? null);
        };
        $cell = static fn (string $row, string $column): ?array => $cells[$row.'!'.$column] ?? null;
        $group = static fn (array $node): array => ['value' => $node['value'] ?? null, 'label' => $node['label'] ?? null];

        $payload = [
            'spec' => $chart->toArray(),
            'measure' => $chart->value === ReportDefinition::RECORD_COUNT
                ? null
                : array_values(array_filter($aggregates, static fn (array $aggregate): bool => ($aggregate['column'] ?? null) === $chart->value))[0] ?? null,
            'total' => $value($cell(FactMap::TOTAL, FactMap::TOTAL)),
            'category_axis' => null,
            'categories' => [],
            'series_axis' => null,
            'series' => [],
            'data' => [],
        ];

        if ($chart->type->isSingleValue()) {
            return $payload;
        }

        // A matrix grouped by columns only: its columns are the categories.
        if ($chart->x === 'x0') {
            $payload['category_axis'] = $axes['across'][0] ?? null;
            $payload['categories'] = array_map($group, $across);
            $payload['data'] = [array_map(static fn (array $column): mixed => $value($cell(FactMap::TOTAL, (string) $column['key'])), $across)];

            return $payload;
        }

        $payload['category_axis'] = $axes['down'][0] ?? null;
        $payload['categories'] = array_map($group, $down);

        if ($chart->series === 'x0') {
            $payload['series_axis'] = $axes['across'][0] ?? null;
            $payload['series'] = array_map($group, $across);
            $payload['data'] = array_map(
                static fn (array $column): array => array_map(static fn (array $row): mixed => $value($cell((string) $row['key'], (string) $column['key'])), $down),
                $across,
            );

            return $payload;
        }

        if ($chart->series === 'g1') {
            // The second grouping's groups, in the order first met across the parents.
            $series = [];
            $byParent = [];
            foreach ($down as $parent) {
                foreach ($parent['groups'] ?? [] as $child) {
                    $id = json_encode($child['value'] ?? null) ?: 'null';
                    $series[$id] ??= $group($child);
                    $byParent[$parent['key']][$id] = $child['key'];
                }
            }

            $payload['series_axis'] = $axes['down'][1] ?? null;
            $payload['series'] = array_values($series);
            $payload['data'] = array_map(
                static fn (string $id): array => array_map(
                    static fn (array $parent): mixed => isset($byParent[$parent['key']][$id]) ? $value($cell((string) $byParent[$parent['key']][$id], FactMap::TOTAL)) : null,
                    $down,
                ),
                array_keys($series),
            );

            return $payload;
        }

        $payload['data'] = [array_map(static fn (array $row): mixed => $value($cell((string) $row['key'], FactMap::TOTAL)), $down)];

        return $payload;
    }
}
