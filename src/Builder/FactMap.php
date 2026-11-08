<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Data\Grouping;

/**
 * Turns the grouped query results of a summary or matrix report into a fact
 * map (SPEC §14.2): two group trees — rows (`g{i}`) and columns (`x{i}`) —
 * whose nodes are keyed by index path (`0`, `0_1`, …), and one cell per
 * `<row path>!<column path>` pair, `T` standing for an axis total. Keys hold
 * positions, never values, so no record data ends up in a key.
 *
 * A group whose parent was cut by a cap has nowhere to hang, so it is
 * dropped; the caller has already flagged the result as truncated.
 */
final class FactMap
{
    public const string TOTAL = 'T';

    /**
     * @param  array<int, array<int, list<array<string, mixed>>>>  $levels  [down depth][across depth] => rows
     * @return array{
     *     groupings_down: list<array<string, mixed>>,
     *     groupings_across: list<array<string, mixed>>,
     *     fact_map: array<string, array{record_count: int, counts: array<string, int>, aggregates: array<string, mixed>}>,
     *     paths: array<string, string>,
     *     cells: int,
     *     truncated: bool
     * }
     */
    public function build(ValidatedDefinition $validated, array $levels, int $levelCount, int $maxCells): array
    {
        $definition = $validated->definition;
        $down = count($definition->groupings);
        $across = count($definition->groupingsAcross);

        $levels = $this->labelBuckets($validated, $levels);

        [$downTree, $downPaths] = $this->tree(
            array_map(static fn (int $depth): array => $levels[$depth][0] ?? [], range(1, max(1, $down))),
            'g',
            $down,
        );
        [$acrossTree, $acrossPaths] = $this->tree(
            array_map(static fn (int $depth): array => $levels[0][$depth] ?? [], range(1, max(1, $across))),
            'x',
            $across,
        );

        $cells = [];
        $truncated = false;
        for ($d = 0; $d <= $down; $d++) {
            for ($a = 0; $a <= $across; $a++) {
                foreach ($levels[$d][$a] ?? [] as $row) {
                    $rowKey = $d === 0 ? self::TOTAL : ($downPaths[self::pathOf($row, 'g', $d)] ?? null);
                    $columnKey = $a === 0 ? self::TOTAL : ($acrossPaths[self::pathOf($row, 'x', $a)] ?? null);

                    if ($rowKey === null || $columnKey === null) {
                        continue;
                    }

                    if (count($cells) >= $maxCells) {
                        $truncated = true;
                        break 3;
                    }

                    $cells[$rowKey.'!'.$columnKey] = $this->totals($row, count($definition->aggregates), $levelCount);
                }
            }
        }

        return [
            'groupings_down' => $downTree,
            'groupings_across' => $acrossTree,
            'fact_map' => $cells,
            'paths' => $downPaths,
            'cells' => count($cells),
            'truncated' => $truncated,
        ];
    }

    /**
     * The lookup key of a row's first $depth group values on one axis.
     *
     * @param  array<string, mixed>  $row
     */
    public static function pathOf(array $row, string $prefix, int $depth): string
    {
        $values = [];
        for ($i = 0; $i < $depth; $i++) {
            $values[] = $row[$prefix.$i] ?? null;
        }

        return (string) json_encode($values);
    }

    /**
     * Nodes per depth, children in the order their query returned them.
     *
     * @param  list<list<array<string, mixed>>>  $rowsByDepth  depth 1..n
     * @return array{list<array<string, mixed>>, array<string, string>} the tree, and value path => index path
     */
    private function tree(array $rowsByDepth, string $prefix, int $depth): array
    {
        if ($depth === 0) {
            return [[], []];
        }

        $paths = [];
        $nodes = [];
        $children = [];
        $childCount = [];

        foreach (array_slice($rowsByDepth, 0, $depth) as $index => $rows) {
            $k = $index + 1;
            foreach ($rows as $row) {
                $parentKey = null;
                if ($k > 1) {
                    $parentKey = $paths[self::pathOf($row, $prefix, $k - 1)] ?? null;
                    if ($parentKey === null) {
                        continue;
                    }
                }

                $slot = $parentKey ?? '';
                $position = $childCount[$slot] = ($childCount[$slot] ?? -1) + 1;
                $key = $parentKey === null ? (string) $position : $parentKey.'_'.$position;
                $value = $row[$prefix.($k - 1)] ?? null;

                $paths[self::pathOf($row, $prefix, $k)] = $key;
                $nodes[$key] = ['key' => $key, 'value' => $value, 'label' => $value === null ? null : (string) $value];
                $children[$slot][] = $key;
            }
        }

        return [$this->assemble('', $nodes, $children), $paths];
    }

    /**
     * PHP turns index keys like "0" into ints, hence array-key.
     *
     * @param  array<array-key, array<string, mixed>>  $nodes
     * @param  array<array-key, list<string>>  $children  parent key ('' = top) => child keys
     * @return list<array<string, mixed>>
     */
    private function assemble(string $slot, array $nodes, array $children): array
    {
        return array_map(fn (string $key): array => [...$nodes[$key], 'groups' => $this->assemble($key, $nodes, $children)], $children[$slot] ?? []);
    }

    /**
     * A bucket groups by its index; every row shows its label instead.
     *
     * @param  array<int, array<int, list<array<string, mixed>>>>  $levels
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function labelBuckets(ValidatedDefinition $validated, array $levels): array
    {
        $axes = ['g' => $validated->definition->groupings, 'x' => $validated->definition->groupingsAcross];

        foreach ($levels as $d => $byAcross) {
            foreach ($byAcross as $a => $rows) {
                foreach ($rows as $r => $row) {
                    foreach ($axes as $prefix => $groupings) {
                        foreach (array_slice($groupings, 0, $prefix === 'g' ? $d : $a) as $i => $grouping) {
                            /** @var Grouping $grouping */
                            $bucket = $validated->buckets[$grouping->field]['bucket'] ?? null;
                            if ($bucket !== null) {
                                $levels[$d][$a][$r][$prefix.$i] = $bucket->labelFor($row[$prefix.$i] ?? null);
                            }
                        }
                    }
                }
            }
        }

        return $levels;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{record_count: int, counts: array<string, int>, aggregates: array<string, mixed>}
     */
    private function totals(array $row, int $aggregateCount, int $levelCount): array
    {
        $counts = [];
        for ($level = 0; $level < $levelCount; $level++) {
            $counts['l'.$level] = (int) ($row['n'.$level] ?? 0);
        }

        $aggregates = [];
        for ($i = 0; $i < $aggregateCount; $i++) {
            $value = $row['a'.$i] ?? null;
            $aggregates['a'.$i] = is_numeric($value) ? $value + 0 : $value;
        }

        return ['record_count' => (int) ($row['record_count'] ?? 0), 'counts' => $counts, 'aggregates' => $aggregates];
    }
}
