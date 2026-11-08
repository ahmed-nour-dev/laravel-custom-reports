<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which records a viewer may see — the app's rule, applied by the engine on
 * every level and lookup of a report (SPEC §6.3 rule 2), and behind the
 * "mine / my team" scope (§14.3). The engine never decides visibility
 * itself; an app binds its own implementation.
 */
interface ReportVisibility
{
    /** True when $viewer sees every row of $model, so no constraint is needed. */
    public function seesAll(Authenticatable $viewer, Model $model): bool;

    /**
     * Narrow $query — a query over the model's own (unaliased) table — to the
     * rows $viewer may see. The engine uses it as `pk IN (this query)`.
     *
     * @param  Builder<Model>  $query
     */
    public function constrain(Builder $query, Authenticatable $viewer): void;

    /** Whether records of $model have an owner, so "mine" / "my team" mean something. */
    public function hasOwner(Model $model): bool;

    /**
     * Rows owned by $viewer — or, with $team, by their team — as a filter
     * the viewer chose: it only narrows, whatever wider access they have.
     * $alias names the table when the query aliases it (a report's `l0`).
     *
     * @param  Builder<Model>|QueryBuilder  $query
     */
    public function constrainToOwner(Builder|QueryBuilder $query, Model $model, Authenticatable $viewer, bool $team = false, ?string $alias = null): void;
}
