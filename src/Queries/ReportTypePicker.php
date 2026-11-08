<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\ReportTypeFilters;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The report types a user may build on (SPEC §10): deployed ones, plus
 * those in development for `manage_report_types` holders, with their
 * objects, narrowed by ReportTypeFilters (`object`, `field`).
 *
 * Each type carries `last_used_at`: when the user last saved one of their
 * own reports on it (the picker's "Recently used" list), or null.
 */
final readonly class ReportTypePicker
{
    public function __construct(private ReportPermissions $permissions) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ReportType>
     */
    public function handle(Authenticatable $user, array $filters = []): Collection
    {
        return ReportType::query()
            ->with('objects')
            ->withMax(['reports as last_used_at' => fn ($q) => $q->where('owner_id', Users::id($user))], 'updated_at')
            ->when(! $this->seesDevelopment($user), fn ($q) => $q->where('status', ReportTypeStatus::DEPLOYED->value))
            ->tap(fn ($q) => ReportTypeFilters::apply($q, $filters))
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function isAvailableTo(ReportType $type, Authenticatable $user): bool
    {
        return $type->status === ReportTypeStatus::DEPLOYED || $this->seesDevelopment($user);
    }

    private function seesDevelopment(Authenticatable $user): bool
    {
        return $this->permissions->canManageReportTypes($user);
    }
}
