<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support\Defaults;

use AhmedNour\CustomReports\Contracts\ReportVisibility;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * The default visibility: every viewer sees every record, and no record has
 * an owner (so a report offers no "mine / my team" scope). An app with
 * record-level rules binds its own ReportVisibility.
 */
final readonly class OpenVisibility implements ReportVisibility
{
    public function seesAll(Authenticatable $viewer, Model $model): bool
    {
        return true;
    }

    public function constrain(Builder $query, Authenticatable $viewer): void {}

    public function hasOwner(Model $model): bool
    {
        return false;
    }

    public function constrainToOwner(Builder|QueryBuilder $query, Model $model, Authenticatable $viewer, bool $team = false, ?string $alias = null): void
    {
        throw new LogicException($model::class.' has no owner.');
    }
}
