<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Data\SortOrder;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;

/**
 * Turns field keys into `c{n}` columns, adding the lookup joins they need,
 * and adds the per-level primary keys the stable ordering relies on.
 */
final class SelectCompiler
{
    /**
     * @param  list<string>  $keys
     * @param  array<string, Bucket>  $buckets  `bucket:{key}` => bucket, for bucket columns
     * @param  array{sql: string, bindings: list<int|float>, level: int, decimals: int}|null  $rowFormula  RowFormulaCompiler's SQL, for RowFormula::KEY
     * @return list<SelectedColumn>
     */
    public function select(CompiledQuery $compiled, array $keys, array $buckets = [], ?array $rowFormula = null): array
    {
        $max = (int) config('custom-reports.max_columns', 100);

        if (count($keys) > $max) {
            throw InvalidReportType::fieldCap(count($keys), $max);
        }

        $columns = [];
        foreach (array_values($keys) as $position => $key) {
            $column = 'c'.$position;

            if ($key === RowFormula::KEY && $rowFormula !== null) {
                $compiled->query->addSelect(new CompiledSql($rowFormula['sql'].' as '.$compiled->query->getGrammar()->wrap($column)))
                    ->addBinding($rowFormula['bindings'], 'select');
                $level = $rowFormula['level'];
                $columns[] = new SelectedColumn($column, Alias::level($level), FieldRef::make($level, null, 'row_formula'), Field::decimal('row_formula'), $compiled->levels[$level], isRowFormula: true, rowDecimals: $rowFormula['decimals']);

                continue;
            }

            $bucket = $buckets[$key] ?? null;
            $ref = FieldRef::parse($bucket instanceof Bucket ? $bucket->field : $key);
            [$alias, $source] = $compiled->joins->resolve($ref->level, $ref->path);
            $field = $source->field($ref->field) ?? throw InvalidReportType::unknownField($key);

            $compiled->query->addSelect($bucket instanceof Bucket
                ? ColumnExpression::bucket($bucket, $alias.'.'.$field->name, $column)
                : $alias.'.'.$field->name.' as '.$column);
            $columns[] = new SelectedColumn($column, $alias, $ref, $field, $source, $bucket);
        }

        return $columns;
    }

    /**
     * The user's sort, before the tiebreak.
     *
     * @param  list<SortOrder>  $sorts
     * @param  array{sql: string, bindings: list<int|float>, level: int, decimals: int}|null  $rowFormula
     */
    public function orderBy(CompiledQuery $compiled, array $sorts, ?array $rowFormula = null): void
    {
        foreach ($sorts as $sort) {
            if ($sort->field === RowFormula::KEY && $rowFormula !== null) {
                $compiled->query->orderBy(new CompiledSql($rowFormula['sql']), $sort->dir)->addBinding($rowFormula['bindings'], 'order');

                continue;
            }

            $ref = FieldRef::parse($sort->field);
            [$alias, $source] = $compiled->joins->resolve($ref->level, $ref->path);
            $field = $source->field($ref->field) ?? throw InvalidReportType::unknownField($sort->field);

            $compiled->query->orderBy($alias.'.'.$field->name, $sort->dir);
        }
    }

    /**
     * Order by every level's primary key, l0 first, after any user sort, so
     * pages are stable. The keys are selected as `__pk{N}` and never leave
     * the backend.
     */
    public function tiebreak(CompiledQuery $compiled): void
    {
        foreach ($compiled->levels as $level => $source) {
            $column = Alias::level($level).'.'.$source->keyName();
            $compiled->query->addSelect($column.' as '.Alias::primaryKey($level))->orderBy($column);
        }
    }
}
