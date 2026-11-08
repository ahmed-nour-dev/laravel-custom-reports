<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Compiler\Alias;
use AhmedNour\CustomReports\Builder\Compiler\BaseQueryCompiler;
use AhmedNour\CustomReports\Builder\Compiler\CompiledQuery;
use AhmedNour\CustomReports\Builder\Compiler\CrossFilterCompiler;
use AhmedNour\CustomReports\Builder\Compiler\FilterCompiler;
use AhmedNour\CustomReports\Builder\Compiler\Guardrail;
use AhmedNour\CustomReports\Builder\Compiler\RowFormulaCompiler;
use AhmedNour\CustomReports\Builder\Compiler\ScopeFilterCompiler;
use AhmedNour\CustomReports\Builder\Compiler\SelectCompiler;
use AhmedNour\CustomReports\Builder\Compiler\SelectedColumn;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Enums\FieldType;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Runs a tabular custom report for a viewer (SPEC §6.4, §8.2): always the
 * *runner's* visibility, never the author's. Rows are `{c0, c1, …}` at the
 * grain of the deepest level; identity cells are `{code, route}`; primary
 * keys are used for the order tiebreak and never leave the query.
 */
final readonly class RunCustomReport
{
    public function __construct(
        private BaseQueryCompiler $compiler,
        private SelectCompiler $select,
        private FilterCompiler $filters,
        private CrossFilterCompiler $crossFilters,
        private ScopeFilterCompiler $scope,
        private Guardrail $guardrail,
        private RowFormulaCompiler $rowFormulas,
    ) {}

    /**
     * @return array{
     *     columns: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function handle(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, int $page = 1, int $perPage = 25): array
    {
        [$compiled, $columns] = $this->compile($type, $validated, $viewer);

        $connection = $compiled->query->getConnection();
        $count = $compiled->query->newQuery()->fromSub(clone $compiled->query, 't');
        $total = $this->guardrail->run($connection, fn (): int => $count->count());

        $this->ordered($compiled, $validated);

        $page = max(1, $page);
        $rows = $this->guardrail->run($connection, fn () => $compiled->query->forPage($page, $perPage)->get());

        return [
            'columns' => $this->describe($columns, $validated),
            'rows' => array_values(array_map(fn (object $row): array => $this->row($row, $columns), $rows->all())),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    /**
     * The filtered, column-selected query — shared with the export.
     *
     * @return array{CompiledQuery, list<SelectedColumn>}
     */
    public function compile(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): array
    {
        $compiled = $this->compiler->base($type, $viewer, $this->filters->joinConstraint($validated, $viewer));

        $buckets = array_map(static fn (array $bucket): Bucket => $bucket['bucket'], $validated->buckets);
        $columns = $this->select->select($compiled, $validated->definition->columns, $buckets, $this->rowFormulas->compile($compiled, $validated));

        foreach ($columns as $column) {
            $route = $column->field->isIdentity() ? $column->source->route() : null;

            if ($route !== null) {
                $compiled->query->addSelect($column->tableAlias.'.'.$route[1].' as __route_'.$column->alias);
            }
        }

        $this->filters->apply($compiled, $validated);
        $this->scope->apply($compiled, $validated, $viewer);
        $this->crossFilters->apply($compiled, $validated, $viewer);

        return [$compiled, $columns];
    }

    /** The user's sort, then the primary-key tiebreak, so pages are stable. */
    public function ordered(CompiledQuery $compiled, ValidatedDefinition $validated): void
    {
        $this->select->orderBy($compiled, $validated->definition->sort, $this->rowFormulas->compile($compiled, $validated));
        $this->select->tiebreak($compiled);
    }

    /**
     * @param  list<SelectedColumn>  $columns
     * @return list<array<string, mixed>>
     */
    public function describe(array $columns, ValidatedDefinition $validated): array
    {
        return array_map(static fn (SelectedColumn $column): array => match (true) {
            $column->isRowFormula => [...$validated->describe(RowFormula::KEY), 'column' => $column->alias, 'linked' => false],
            $column->bucket instanceof Bucket => [
                'key' => $column->key(), 'label' => $column->bucket->label, 'label_ar' => null, 'label_key' => null,
                'type' => 'bucket', 'repeated' => $validated->field($column->ref->key)->isRepeated, 'identity' => false,
                'column' => $column->alias, 'linked' => false,
            ],
            default => [
                ...$validated->field($column->ref->key)->describe(),
                'column' => $column->alias,
                'linked' => $column->field->isIdentity() && $column->source->route() !== null,
            ],
        }, $columns);
    }

    /**
     * @param  list<SelectedColumn>  $columns
     * @return array<string, mixed>
     */
    public function row(object $row, array $columns): array
    {
        $values = (array) $row;
        $cells = [];

        foreach ($columns as $column) {
            $value = $values[$column->alias] ?? null;
            $route = $column->field->isIdentity() && ! $column->bucket instanceof Bucket ? $column->source->route() : null;

            if ($column->isRowFormula) {
                $value = is_numeric($value) ? round((float) $value, $column->rowDecimals) : null;
                $value = $value == 0.0 && $value !== null ? 0.0 : $value;
            } elseif ($column->bucket instanceof Bucket) {
                $value = $column->bucket->labelFor($value);
            } elseif ($route !== null && $value !== null) {
                $routeValue = $values['__route_'.$column->alias] ?? null;
                $value = [
                    'code' => $value,
                    'route' => $routeValue === null ? null : str_replace('{code}', rawurlencode((string) $routeValue), $route[0]),
                ];
            } elseif ($column->field->type === FieldType::BOOLEAN && $value !== null) {
                $value = (bool) $value;
            }

            $cells[$column->alias] = $value;
        }

        return array_filter($cells, static fn (string $key): bool => ! Alias::isInternal($key), ARRAY_FILTER_USE_KEY);
    }
}
