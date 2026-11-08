<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Definition\LogicNode;
use AhmedNour\CustomReports\Builder\Definition\ResolvedField;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Data\FilterCondition;
use AhmedNour\CustomReports\Enums\FieldType;
use AhmedNour\CustomReports\Enums\FilterOperator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Standard filters (SPEC §7.1): the parsed logic becomes nested where /
 * orWhere / whereNot groups, every value is bound, and every column comes
 * from the registry through the join registry.
 *
 * Rule 5: a filter on an outer ("may or may not have") level goes in that
 * level's ON instead (joinConstraint()), so it narrows the joined rows
 * without dropping the parent. A lookup field on such a level becomes a key
 * subquery — `l1.fk IN (SELECT pk FROM parent WHERE …)` — because lookup
 * joins come after the level's join; every hop is visibility-scoped.
 * "Has / has no related B" is `l{N}.pk IS [NOT] NULL` in WHERE.
 *
 * "Not equals", "not in" and "does not contain" keep blank values, the way
 * a user reads them ("region is not Cairo" includes accounts with no region).
 *
 * Comparing two fields (SPEC §14.3) is `whereColumn`. A blank side never
 * matches, except under "not equals", which keeps rows where exactly one
 * side is blank. On an outer level both fields are that level's own, so the
 * comparison goes in its ON like any other rule-5 filter.
 */
final readonly class FilterCompiler
{
    public function __construct(
        private SourceRegistry $registry,
        private ScopeApplier $scopes,
    ) {}

    /**
     * The ON additions for outer levels (rule 5a), for BaseQueryCompiler::base().
     *
     * @return (Closure(JoinClause, int): void)|null
     */
    public function joinConstraint(ValidatedDefinition $validated, Authenticatable $viewer): ?Closure
    {
        if ($validated->outerFilters === []) {
            return null;
        }

        return function (JoinClause $join, int $level) use ($validated, $viewer): void {
            foreach ($validated->outerFilters[$level] ?? [] as $filter) {
                $resolved = $validated->field($filter->field);
                $alias = Alias::level($level);

                if ($filter->compareField !== null) {
                    // DefinitionValidator: both are this level's own fields.
                    $other = $validated->field($filter->compareField);
                    $this->condition($join, $filter, $alias.'.'.$resolved->field->name, $resolved->field->type, 'and', $alias.'.'.$other->field->name);

                    continue;
                }

                if ($resolved->ref->path === null) {
                    $this->condition($join, $filter, $alias.'.'.$resolved->field->name, $resolved->field->type, 'and');

                    continue;
                }

                $hops = explode('.', $resolved->ref->path);
                $edge = $this->registry->lookup($resolved->levelSource ?? $resolved->source, $hops[0]);
                $join->whereIn($alias.'.'.$edge->foreignKey, $this->keySubquery($join, $edge, array_slice($hops, 1), $filter, $resolved, $viewer, 1));
            }
        };
    }

    /**
     * `SELECT pk FROM <hop> WHERE <next hop key> IN (…)` down to the filtered
     * field, each hop under its own visibility.
     *
     * @param  list<string>  $rest
     */
    private function keySubquery(Builder $parent, Edge $edge, array $rest, FilterCondition $filter, ResolvedField $resolved, Authenticatable $viewer, int $depth): Builder
    {
        $alias = 'k'.$depth;
        $target = $edge->parent;
        // A plain builder: newQuery() on a JoinClause would render its wheres as ON.
        $query = (new Builder($parent->getConnection(), $parent->getGrammar(), $parent->getProcessor()))
            ->from($target->table().' as '.$alias)
            ->select($alias.'.'.$edge->ownerKey);
        $this->scopes->applyToWhere($query, $target, $alias, $viewer);

        if ($rest === []) {
            $this->condition($query, $filter, $alias.'.'.$resolved->field->name, $resolved->field->type, 'and');

            return $query;
        }

        $next = $this->registry->lookup($target, $rest[0]);

        return $query->whereIn($alias.'.'.$next->foreignKey, $this->keySubquery($query, $next, array_slice($rest, 1), $filter, $resolved, $viewer, $depth + 1));
    }

    public function apply(CompiledQuery $compiled, ValidatedDefinition $validated): void
    {
        // 5c: "has / has no related B".
        foreach ($validated->related as $related) {
            $column = Alias::level($related->level).'.'.$compiled->levels[$related->level]->keyName();
            $related->has ? $compiled->query->whereNotNull($column) : $compiled->query->whereNull($column);
        }

        $logic = $validated->whereLogic;

        if (! $logic instanceof LogicNode) {
            return;
        }

        $filters = [];
        foreach ($validated->definition->filters as $filter) {
            if (! in_array($filter->n, $logic->numbers(), true)) {
                continue;
            }

            $resolved = $validated->field($filter->field);
            [$alias] = $compiled->joins->resolve($resolved->ref->level, $resolved->ref->path);
            $other = null;

            if ($filter->compareField !== null) {
                $compare = $validated->field($filter->compareField);
                [$otherAlias] = $compiled->joins->resolve($compare->ref->level, $compare->ref->path);
                $other = $otherAlias.'.'.$compare->field->name;
            }

            $filters[$filter->n] = [$filter, $alias.'.'.$resolved->field->name, $resolved->field->type, $other];
        }

        $compiled->query->where(fn (Builder $where) => $this->node($where, $logic, $filters, 'and'));
    }

    /**
     * @param  array<int, array{FilterCondition, string, FieldType, string|null}>  $filters
     */
    private function node(Builder $query, LogicNode $node, array $filters, string $boolean): void
    {
        match ($node->kind) {
            LogicNode::REF => $this->condition($query, $filters[$node->number][0], $filters[$node->number][1], $filters[$node->number][2], $boolean, $filters[$node->number][3]),
            LogicNode::NOT => $query->whereNot(fn (Builder $inner) => $this->node($inner, $node->children[0], $filters, 'and'), boolean: $boolean),
            default => $query->where(function (Builder $group) use ($node, $filters): void {
                foreach ($node->children as $child) {
                    $this->node($group, $child, $filters, $node->kind === LogicNode::OR ? 'or' : 'and');
                }
            }, boolean: $boolean),
        };
    }

    /** $compareColumn: the other field's column when the filter compares two fields. */
    public function condition(Builder $query, FilterCondition $filter, string $column, FieldType $type, string $boolean, ?string $compareColumn = null): void
    {
        if ($compareColumn !== null) {
            $this->compare($query, $filter->op, $column, $compareColumn, $boolean);

            return;
        }

        $value = $filter->value;
        $isDateTime = $type === FieldType::DATETIME;

        if (in_array($filter->op, FilterOperator::relativeDates(), true)) {
            [$from, $to] = $this->range($filter->op, is_int($value) ? $value : 0);
            $query->whereBetween($column, $isDateTime
                ? [$from->toDateTimeString(), $to->toDateTimeString()]
                : [$from->toDateString(), $to->toDateString()], $boolean);

            return;
        }

        if ($isDateTime && is_string($value) && strlen($value) === 10) {
            $this->dateOnDateTime($query, $filter->op, $column, $value, $boolean);

            return;
        }

        if ($isDateTime && $filter->op === FilterOperator::BETWEEN && is_array($value)) {
            // Plain dates mean whole days: from the start of the first to the end of the last.
            [$from, $to] = array_values($value);
            $query->whereBetween($column, [
                is_string($from) && strlen($from) === 10 ? $from.' 00:00:00' : $from,
                is_string($to) && strlen($to) === 10 ? $to.' 23:59:59' : $to,
            ], $boolean);

            return;
        }

        if ($type === FieldType::BOOLEAN && is_bool($value)) {
            $value = (int) $value;
        }

        match ($filter->op) {
            FilterOperator::EQUALS => $query->where($column, '=', $value, $boolean),
            FilterOperator::NOT_EQUALS => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '!=', $value), boolean: $boolean),
            FilterOperator::IN => $query->whereIn($column, (array) $value, $boolean),
            FilterOperator::NOT_IN => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, (array) $value), boolean: $boolean),
            FilterOperator::CONTAINS => $query->where($column, 'like', '%'.$this->escapeLike((string) $value).'%', $boolean),
            FilterOperator::NOT_CONTAINS => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, 'not like', '%'.$this->escapeLike((string) $value).'%'), boolean: $boolean),
            FilterOperator::STARTS_WITH => $query->where($column, 'like', $this->escapeLike((string) $value).'%', $boolean),
            FilterOperator::LT => $query->where($column, '<', $value, $boolean),
            FilterOperator::LTE => $query->where($column, '<=', $value, $boolean),
            FilterOperator::GT => $query->where($column, '>', $value, $boolean),
            FilterOperator::GTE => $query->where($column, '>=', $value, $boolean),
            FilterOperator::BETWEEN => $query->whereBetween($column, array_values((array) $value), $boolean),
            FilterOperator::IS_NULL => $query->whereNull($column, $boolean),
            FilterOperator::IS_NOT_NULL => $query->whereNotNull($column, $boolean),
            default => null,
        };
    }

    private function compare(Builder $query, FilterOperator $op, string $column, string $other, string $boolean): void
    {
        match ($op) {
            FilterOperator::EQUALS => $query->whereColumn($column, '=', $other, $boolean),
            FilterOperator::NOT_EQUALS => $query->where(fn (Builder $q) => $q
                ->whereColumn($column, '!=', $other)
                ->orWhere(fn (Builder $blank) => $blank->whereNull($column)->whereNotNull($other))
                ->orWhere(fn (Builder $blank) => $blank->whereNotNull($column)->whereNull($other)), boolean: $boolean),
            FilterOperator::LT => $query->whereColumn($column, '<', $other, $boolean),
            FilterOperator::LTE => $query->whereColumn($column, '<=', $other, $boolean),
            FilterOperator::GT => $query->whereColumn($column, '>', $other, $boolean),
            FilterOperator::GTE => $query->whereColumn($column, '>=', $other, $boolean),
            default => null,
        };
    }

    /** A plain date against a datetime column means the whole day. */
    private function dateOnDateTime(Builder $query, FilterOperator $op, string $column, string $date, string $boolean): void
    {
        $start = $date.' 00:00:00';
        $end = $date.' 23:59:59';

        match ($op) {
            FilterOperator::EQUALS => $query->whereBetween($column, [$start, $end], $boolean),
            FilterOperator::LT => $query->where($column, '<', $start, $boolean),
            FilterOperator::LTE => $query->where($column, '<=', $end, $boolean),
            FilterOperator::GT => $query->where($column, '>', $end, $boolean),
            FilterOperator::GTE => $query->where($column, '>=', $start, $boolean),
            default => null,
        };
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(FilterOperator $op, int $days): array
    {
        $now = CarbonImmutable::now();

        return match ($op) {
            FilterOperator::TODAY => [$now->startOfDay(), $now->endOfDay()],
            FilterOperator::YESTERDAY => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            FilterOperator::THIS_WEEK => [$now->startOfWeek(), $now->endOfWeek()],
            FilterOperator::LAST_WEEK => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            FilterOperator::THIS_MONTH => [$now->startOfMonth(), $now->endOfMonth()],
            FilterOperator::LAST_MONTH => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            FilterOperator::THIS_QUARTER => [$now->startOfQuarter(), $now->endOfQuarter()],
            FilterOperator::LAST_QUARTER => [$now->subQuarterNoOverflow()->startOfQuarter(), $now->subQuarterNoOverflow()->endOfQuarter()],
            FilterOperator::THIS_YEAR => [$now->startOfYear(), $now->endOfYear()],
            FilterOperator::LAST_YEAR => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            FilterOperator::LAST_N_DAYS => [$now->subDays($days)->startOfDay(), $now->endOfDay()],
            default => [$now->startOfDay(), $now->addDays($days)->endOfDay()],
        };
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
