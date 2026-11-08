<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * Cross filters (SPEC §7.3, rule 6): "accounts with / without orders
 * [that match …]" as a correlated EXISTS / NOT EXISTS on the primary object,
 * built from the child link. The subquery is visibility-scoped, so a child
 * the viewer can't see counts as absent — a cross filter never reveals that
 * a hidden record exists.
 */
final readonly class CrossFilterCompiler
{
    public function __construct(
        private ScopeApplier $scopes,
        private FilterCompiler $filters,
    ) {}

    public function apply(CompiledQuery $compiled, ValidatedDefinition $validated, Authenticatable $viewer): void
    {
        foreach ($validated->crossFilters as $index => $cross) {
            $edge = $cross['edge'];
            $alias = 'x'.$index;

            $subquery = function (Builder $exists) use ($edge, $alias, $cross, $viewer): void {
                $exists->selectRaw('1')
                    ->from($edge->child->table().' as '.$alias)
                    ->whereColumn($alias.'.'.$edge->foreignKey, '=', Alias::level(0).'.'.$edge->ownerKey);

                if ($edge->morphType !== null) {
                    $exists->where($alias.'.'.$edge->morphType, '=', $edge->morphClass);
                }

                $this->scopes->applyToWhere($exists, $edge->child, $alias, $viewer);

                foreach ($cross['filters'] as [$filter, $field]) {
                    $this->filters->condition($exists, $filter, $alias.'.'.$field->name, $field->type, 'and');
                }

            };

            $cross['with'] ? $compiled->query->whereExists($subquery) : $compiled->query->whereNotExists($subquery);
        }
    }
}
