<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Fixtures;

use AhmedNour\CustomReports\Contracts\ReportVisibility;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A different app's rule: a user sees the customers they own (and their
 * orders); the user named "Manager" sees everything.
 */
final readonly class OwnerVisibility implements ReportVisibility
{
    public function seesAll(Authenticatable $viewer, Model $model): bool
    {
        return $viewer instanceof User && $viewer->name === 'Manager';
    }

    public function constrain(Builder $query, Authenticatable $viewer): void
    {
        $id = Users::id($viewer);

        match (true) {
            $query->getModel() instanceof Customer => $query->where('owner_id', $id),
            $query->getModel() instanceof Order => $query->whereHas('customer', fn (Builder $customer) => $customer->where('owner_id', $id)),
            default => null,
        };
    }

    public function hasOwner(Model $model): bool
    {
        return $model instanceof Customer;
    }

    public function constrainToOwner(Builder|QueryBuilder $query, Model $model, Authenticatable $viewer, bool $team = false, ?string $alias = null): void
    {
        $query->where(($alias ?? $model->getTable()).'.owner_id', Users::id($viewer));
    }
}
