<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\ChartData;
use AhmedNour\CustomReports\Builder\Compiler\AggregateCompiler;
use AhmedNour\CustomReports\Builder\Compiler\BaseQueryCompiler;
use AhmedNour\CustomReports\Builder\Compiler\CompiledQuery;
use AhmedNour\CustomReports\Builder\Compiler\CrossFilterCompiler;
use AhmedNour\CustomReports\Builder\Compiler\FilterCompiler;
use AhmedNour\CustomReports\Builder\Compiler\Guardrail;
use AhmedNour\CustomReports\Builder\Compiler\ScopeFilterCompiler;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Builder\Formula\Evaluator;
use AhmedNour\CustomReports\Data\ChartSpec;
use AhmedNour\CustomReports\Data\Grouping;
use AhmedNour\CustomReports\Data\GroupSort;
use AhmedNour\CustomReports\Data\Highlight;
use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Data\TopN;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * Runs a summary or matrix custom report (SPEC §14.2) for a viewer, with the
 * viewer's own visibility. One grouped query per (row depth, column depth)
 * pair — (D+1)×(A+1) queries, all inside one Guardrail session — so neither
 * ROLLUP nor GROUPING() is needed, which MySQL and MariaDB don't share.
 *
 * Nothing is cut silently: leaf groups per axis stop at MAX_GROUPS, cells
 * at `custom-reports.max_matrix_cells` and detail rows at
 * `custom-reports.max_detail_rows`, and hitting any of them sets
 * `meta.truncated`.
 */
final readonly class RunMatrixReport
{
    /** Groups per axis returned at most. */
    public const int MAX_GROUPS = 1000;

    public function __construct(
        private BaseQueryCompiler $compiler,
        private FilterCompiler $filters,
        private CrossFilterCompiler $crossFilters,
        private ScopeFilterCompiler $scope,
        private AggregateCompiler $aggregates,
        private Guardrail $guardrail,
        private RunCustomReport $tabular,
        private FactMap $factMap,
        private ChartData $chartData,
        private Evaluator $formulas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): array
    {
        $definition = $validated->definition;
        $down = count($definition->groupings);
        $across = count($definition->groupingsAcross);
        $maxCells = max(1, (int) config('custom-reports.max_matrix_cells', 10000));
        $maxDetail = max(1, (int) config('custom-reports.max_detail_rows', 2000));

        $probe = $this->compile($type, $validated, $viewer, 0, 0);
        $sources = $probe->levels;

        return $this->guardrail->run($probe->query->getConnection(), function () use ($type, $validated, $viewer, $definition, $down, $across, $maxCells, $maxDetail, $sources): array {
            $top = $definition->topN instanceof TopN ? $this->topValues($type, $validated, $viewer, $definition->topN) : null;

            $levels = [];
            $truncated = false;
            for ($d = 0; $d <= $down; $d++) {
                for ($a = 0; $a <= $across; $a++) {
                    $compiled = $this->compile($type, $validated, $viewer, $d, $a, $top);
                    $cap = match (true) {
                        $d > 0 && $a > 0 => $maxCells,
                        $d > 0 || $a > 0 => self::MAX_GROUPS,
                        default => null,
                    };

                    if ($cap !== null) {
                        $compiled->query->limit($cap + 1);
                    }

                    /** @var list<array<string, mixed>> $rows */
                    $rows = array_values(array_map(static fn (object $row): array => (array) $row, $compiled->query->get()->all()));

                    if ($cap !== null && count($rows) > $cap) {
                        $truncated = true;
                        $rows = array_slice($rows, 0, $cap);
                    }

                    $levels[$d][$a] = $rows;
                }
            }

            $map = $this->factMap->build($validated, $levels, count($sources), $maxCells);
            $map['fact_map'] = $this->formulas->apply($validated->formulas, $map['fact_map']);
            $truncated = $truncated || $map['truncated'];

            $detail = null;
            if ($definition->hasDetailRows) {
                $detail = $this->detailRows($type, $validated, $viewer, $top, $map['paths'], $maxDetail);
                $truncated = $truncated || $detail['truncated'];
            }

            $axes = [
                'down' => array_map(fn (Grouping $grouping): array => $this->describe($validated, $grouping), $definition->groupings),
                'across' => array_map(fn (Grouping $grouping): array => $this->describe($validated, $grouping), $definition->groupingsAcross),
            ];
            $aggregates = array_map(fn ($aggregate, int $index): array => [
                ...$validated->describe($aggregate->field),
                'fn' => $aggregate->fn->value,
                'column' => 'a'.$index,
            ], $definition->aggregates, array_keys($definition->aggregates));

            return [
                'format' => $validated->format->value,
                'levels' => array_values(array_map(static fn ($source): string => $source->key(), $sources)),
                'axes' => $axes,
                'aggregates' => $aggregates,
                'formulas' => array_map(static fn (SummaryFormula $formula, int $index): array => [
                    'column' => 'f'.$index,
                    'label' => $formula->label,
                    'type' => $formula->type->value,
                    'decimals' => $formula->decimals,
                    'down' => $formula->down,
                    'across' => $formula->across,
                ], $definition->formulas, array_keys($definition->formulas)),
                'highlights' => array_map(static fn (Highlight $highlight): array => $highlight->toArray(), $definition->highlights),
                'chart' => $definition->chart instanceof ChartSpec
                    ? $this->chartData->build($definition->chart, $axes, $map['groupings_down'], $map['groupings_across'], $map['fact_map'], $aggregates)
                    : null,
                'groupings_down' => $map['groupings_down'],
                'groupings_across' => $map['groupings_across'],
                'fact_map' => $map['fact_map'],
                'display' => [
                    'show_subtotals' => $definition->showSubtotals,
                    'show_grand_total' => $definition->showGrandTotal,
                    'has_detail_rows' => $definition->hasDetailRows,
                ],
                // An object even when the leaf keys are 0, 1, … (PHP would make that a JSON list).
                ...($detail === null ? [] : ['detail' => ['columns' => $detail['columns'], 'rows' => (object) $detail['rows']]]),
                'meta' => [
                    'cells' => $map['cells'],
                    'max_cells' => $maxCells,
                    'max_groups' => self::MAX_GROUPS,
                    'max_detail_rows' => $maxDetail,
                    'truncated' => $truncated,
                ],
            ];
        });
    }

    /**
     * The grouped query for one (row depth, column depth) pair — also what the
     * SQL snapshots pin.
     *
     * @param  list<mixed>|null  $top  the first row grouping's kept values (Top-N)
     */
    public function compile(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, int $down, int $across, ?array $top = null): CompiledQuery
    {
        $compiled = $this->base($type, $validated, $viewer, $top);
        $this->aggregates->summarize($compiled, $validated, $down, $across, $this->sorts($validated));

        return $compiled;
    }

    /**
     * Top-N: the first row grouping's values ranked by the chosen total. The
     * whole report is then narrowed to them, totals included.
     *
     * @return list<mixed>
     */
    private function topValues(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, TopN $top): array
    {
        $compiled = $this->base($type, $validated, $viewer, null);
        $this->aggregates->summarize($compiled, $validated, 1, 0, [0 => new GroupSort(0, $top->aggregate, $top->dir)]);

        // Not pluck(): on the query builder it would replace the select list.
        return array_values(array_map(static fn (object $row): mixed => $row->g0 ?? null, $compiled->query->limit($top->limit)->get()->all()));
    }

    /**
     * Group sorts per depth. Top-N ranks the first grouping, so unless a sort
     * says otherwise its groups are shown in that rank order.
     *
     * @return array<int, GroupSort>
     */
    private function sorts(ValidatedDefinition $validated): array
    {
        $definition = $validated->definition;
        $sorts = [];

        if ($definition->topN instanceof TopN) {
            $sorts[0] = new GroupSort(0, $definition->topN->aggregate, $definition->topN->dir);
        }

        if ($definition->sortAggregate instanceof GroupSort) {
            $sorts[$definition->sortAggregate->depth] = $definition->sortAggregate;
        }

        return $sorts;
    }

    /**
     * @param  list<mixed>|null  $top
     */
    private function base(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, ?array $top): CompiledQuery
    {
        $compiled = $this->compiler->base($type, $viewer, $this->filters->joinConstraint($validated, $viewer));
        $this->filters->apply($compiled, $validated);
        $this->scope->apply($compiled, $validated, $viewer);
        $this->crossFilters->apply($compiled, $validated, $viewer);
        $this->restrictToTop($compiled, $validated, $top);

        return $compiled;
    }

    /**
     * @param  list<mixed>|null  $top
     */
    private function restrictToTop(CompiledQuery $compiled, ValidatedDefinition $validated, ?array $top): void
    {
        if ($top === null) {
            return;
        }

        $expression = $this->aggregates->expression($compiled, $validated, $validated->definition->groupings[0]);
        $values = array_values(array_filter($top, static fn (mixed $value): bool => $value !== null));
        $withNull = count($values) !== count($top);

        $compiled->query->where(static function (Builder $where) use ($expression, $values, $withNull): void {
            $where->whereIn($expression, $values);
            if ($withNull) {
                $where->orWhereNull($expression);
            }
        });
    }

    /**
     * Summary detail rows: the report's columns, at the deepest grain, under
     * the leaf group they belong to (`T` with no groupings).
     *
     * @param  list<mixed>|null  $top
     * @param  array<string, string>  $paths  value path => row group key
     * @return array{columns: list<array<string, mixed>>, rows: array<string, list<array<string, mixed>>>, truncated: bool}
     */
    private function detailRows(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, ?array $top, array $paths, int $max): array
    {
        [$compiled, $columns] = $this->tabular->compile($type, $validated, $viewer);
        $this->restrictToTop($compiled, $validated, $top);

        $groupings = $validated->definition->groupings;
        foreach ($groupings as $index => $grouping) {
            $as = '__g'.$index;
            $compiled->query->addSelect($this->aggregates->expression($compiled, $validated, $grouping, $as))->orderBy($as);
        }
        $this->tabular->ordered($compiled, $validated);

        $rows = array_map(static fn (object $row): object => $row, $compiled->query->limit($max + 1)->get()->all());
        $truncated = count($rows) > $max;

        $grouped = [];
        foreach (array_slice($rows, 0, $max) as $row) {
            $values = (array) $row;
            $path = [];
            foreach ($groupings as $index => $grouping) {
                $value = $values['__g'.$index] ?? null;
                $bucket = $validated->buckets[$grouping->field]['bucket'] ?? null;
                $path['g'.$index] = $bucket === null ? $value : $bucket->labelFor($value);
            }

            $key = $groupings === [] ? FactMap::TOTAL : ($paths[FactMap::pathOf($path, 'g', count($groupings))] ?? null);
            if ($key !== null) {
                $grouped[$key][] = $this->tabular->row($row, $columns);
            }
        }

        return ['columns' => $this->tabular->describe($columns, $validated), 'rows' => $grouped, 'truncated' => $truncated];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ValidatedDefinition $validated, Grouping $grouping): array
    {
        return isset($validated->buckets[$grouping->field])
            ? [
                'key' => $grouping->field, 'label' => $validated->buckets[$grouping->field]['bucket']->label,
                'label_ar' => null, 'label_key' => null, 'type' => 'bucket', 'repeated' => false, 'identity' => false, 'granularity' => null,
            ]
            : [
                ...$validated->field($grouping->field)->describe(),
                'granularity' => $grouping->granularity?->value,
            ];
    }
}
