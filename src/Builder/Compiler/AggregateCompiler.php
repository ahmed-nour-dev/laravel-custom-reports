<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Data\Grouping;
use AhmedNour\CustomReports\Data\GroupSort;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Enums\DateGranularity;
use AhmedNour\CustomReports\Enums\FieldType;
use LogicException;

/**
 * Summary and matrix reports (SPEC §8.3, §7.4, §14.2): group by the first
 * $down row groupings (`g{i}`) and the first $across column groupings
 * (`x{i}`), and select, per group, the record count (rows at the grain of the
 * deepest level), the count of each level's records and the requested
 * aggregates.
 */
final readonly class AggregateCompiler
{
    public function __construct(private RowFormulaCompiler $rowFormulas = new RowFormulaCompiler) {}

    /**
     * @param  array<int, GroupSort>  $sorts  row depth => sort by a total instead of the value
     * @return array{groups: list<string>, across: list<string>, aggregates: list<string>, counts: list<string>}
     *                                                                                                           the aliases selected: g{i}, x{i}, a{i}, n{level}
     */
    public function summarize(CompiledQuery $compiled, ValidatedDefinition $validated, int $down, int $across = 0, array $sorts = []): array
    {
        $query = $compiled->query;
        $query->selectRaw('count(*) as record_count');

        $groups = [];
        foreach (array_slice($validated->definition->groupings, 0, $down) as $index => $grouping) {
            $groups[] = $this->group($compiled, $validated, $grouping, 'g'.$index);
        }

        $columns = [];
        foreach (array_slice($validated->definition->groupingsAcross, 0, $across) as $index => $grouping) {
            $columns[] = $this->group($compiled, $validated, $grouping, 'x'.$index);
        }

        $aggregates = [];
        $rowFormula = null;
        foreach ($validated->definition->aggregates as $index => $aggregate) {
            if ($aggregate->field === RowFormula::KEY) {
                $rowFormula ??= $this->rowFormulas->compile($compiled, $validated) ?? throw new LogicException('The report has no row formula.');
                $query->addSelect(new CompiledSql(ColumnExpression::aggregateSql($aggregate->fn, $rowFormula['sql']).' as '.$query->getGrammar()->wrap('a'.$index)))
                    ->addBinding($rowFormula['bindings'], 'select');
                $aggregates[] = 'a'.$index;

                continue;
            }

            $resolved = $validated->field($aggregate->field);
            [$alias] = $compiled->joins->resolve($resolved->ref->level, $resolved->ref->path);
            $as = 'a'.$index;
            $query->addSelect(ColumnExpression::aggregate($aggregate->fn, $alias.'.'.$resolved->field->name, $as));
            $aggregates[] = $as;
        }

        $counts = [];
        foreach ($compiled->levels as $level => $source) {
            $as = 'n'.$level;
            $query->addSelect(ColumnExpression::distinctCount(Alias::level($level).'.'.$source->keyName(), $as));
            $counts[] = $as;
        }

        // Groups at one depth are ordered by the query that ends at that depth,
        // so a sort by a total only applies there (inside each parent group).
        foreach ($groups as $depth => $as) {
            $sort = $sorts[$depth] ?? null;
            if ($sort instanceof GroupSort && $depth === $down - 1 && $across === 0) {
                $query->orderBy($sort->aggregate, $sort->dir);
            }
            $query->orderBy($as);
        }
        foreach ($columns as $as) {
            $query->orderBy($as);
        }

        return ['groups' => $groups, 'across' => $columns, 'aggregates' => $aggregates, 'counts' => $counts];
    }

    /**
     * The SQL a grouping groups by — a bucket's CASE, a date bucket, or the
     * column itself — without an alias, for WHERE (Top-N) and detail rows.
     */
    public function expression(CompiledQuery $compiled, ValidatedDefinition $validated, Grouping $grouping, ?string $as = null): ColumnExpression
    {
        $bucket = $validated->buckets[$grouping->field] ?? null;

        if ($bucket !== null) {
            $resolved = $bucket['field'];
            [$alias] = $compiled->joins->resolve($resolved->ref->level, $resolved->ref->path);

            return ColumnExpression::bucket($bucket['bucket'], $alias.'.'.$resolved->field->name, $as);
        }

        $resolved = $validated->field($grouping->field);
        [$alias] = $compiled->joins->resolve($resolved->ref->level, $resolved->ref->path);
        $column = $alias.'.'.$resolved->field->name;

        return in_array($resolved->field->type, [FieldType::DATE, FieldType::DATETIME], true)
            ? ColumnExpression::dateBucket($grouping->granularity ?? DateGranularity::DAY, $column, $as)
            : ColumnExpression::column($column, $as);
    }

    private function group(CompiledQuery $compiled, ValidatedDefinition $validated, Grouping $grouping, string $as): string
    {
        $compiled->query->addSelect($this->expression($compiled, $validated, $grouping, $as))->groupBy($as);

        return $as;
    }
}
