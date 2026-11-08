<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Contracts\ReportVisibility;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The only scopes a compiled report applies: record visibility (the app's
 * ReportVisibility) and soft deletes, plus whatever the source declares in
 * constrain(). Nothing else
 * applies implicitly (SPEC §6.3 rule 2).
 *
 * Visibility is always a `{alias}.pk IN (visible rows)` subquery: the
 * models' visibility rules qualify columns with their real table name, so
 * they cannot run against an aliased table directly. On a joined level the
 * subquery goes in ON, never WHERE — in WHERE it would drop the rows an
 * outer join is there to keep.
 */
final readonly class ScopeApplier
{
    public function __construct(private ReportVisibility $visibility) {}

    /** The primary level (l0): constraints go in WHERE. */
    public function applyToWhere(Builder $query, ReportableSource $source, string $alias, Authenticatable $viewer): void
    {
        $this->apply($query, $source, $alias, $viewer);
    }

    /** A joined level or lookup: constraints go in the join's ON. */
    public function applyToJoin(JoinClause $join, ReportableSource $source, string $alias, Authenticatable $viewer): void
    {
        $this->apply($join, $source, $alias, $viewer);
    }

    private function apply(Builder $query, ReportableSource $source, string $alias, Authenticatable $viewer): void
    {
        $model = $source->newModel();

        if (! $this->visibility->seesAll($viewer, $model)) {
            $visible = $model->newQueryWithoutScopes()->select($model->getQualifiedKeyName());
            $this->visibility->constrain($visible, $viewer);
            $query->whereIn($alias.'.'.$model->getKeyName(), $visible->toBase());
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->whereNull($alias.'.deleted_at');
        }

        $source->constrain($query, $alias);
    }
}
