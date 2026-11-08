<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\ReportTypeFilters;
use AhmedNour\CustomReports\Support\SortsListQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** The admin's list of report types, server-paginated and sorted, with each type's objects. */
final readonly class ReportTypeList
{
    use SortsListQueries;

    private const array SORTABLE = ['code', 'name', 'category', 'status', 'primary_source', 'deployed_at', 'created_at', 'updated_at'];

    /**
     * @param  array{search?: string|null, status?: string|null, category?: string|null, object?: string|null, field?: string|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, ReportType>
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return ReportType::query()
            ->with('objects')
            ->withCount(['objects', 'fields'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")))
            ->when(
                ReportTypeStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $q, ReportTypeStatus $status) => $q->where('status', $status->value),
            )
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->tap(fn (Builder $q) => ReportTypeFilters::apply($q, $filters))
            ->tap(fn (Builder $q) => $this->applySort($q, $filters, self::SORTABLE, 'updated_at', 'desc'))
            ->paginate($perPage);
    }
}
