<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Support\ReportAccess;
use AhmedNour\CustomReports\Support\SortsListQueries;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The reports and folders a user can open (SPEC §8.4): their own, those in
 * folders shared to any role they hold, and everything for
 * `manage_report_types`.
 */
final readonly class ReportFolderQuery
{
    use SortsListQueries;

    private const array SORTABLE = ['code', 'name', 'format', 'last_run_at', 'created_at', 'updated_at'];

    public function __construct(
        private ReportAccess $access,
        private ReportPermissions $permissions,
    ) {}

    /**
     * @param  array{search?: string|null, folder?: string|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<int, Report>
     */
    public function reports(Authenticatable $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $folderIds = array_keys($this->access->folders($user));
        $folder = $filters['folder'] ?? null;

        return Report::query()
            ->with(['reportType', 'folder', 'owner'])
            ->when(! $this->permissions->canManageReportTypes($user), fn (Builder $q) => $q->where(fn (Builder $open) => $open
                ->where('owner_id', Users::id($user))
                ->orWhereIn('report_folder_id', $folderIds)))
            ->when($folder === 'private', fn (Builder $q) => $q->whereNull('report_folder_id')->where('owner_id', Users::id($user)))
            ->when(is_string($folder) && $folder !== 'private', fn (Builder $q) => $q->whereHas('folder', fn (Builder $f) => $f->where('code', $folder)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->tap(fn (Builder $q) => $this->applySort($q, $filters, self::SORTABLE, 'updated_at', 'desc'))
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, ReportFolder>
     */
    public function folders(Authenticatable $user): Collection
    {
        $folderIds = array_keys($this->access->folders($user));

        return ReportFolder::query()
            ->with(['shares', 'owner'])
            ->withCount('reports')
            ->when(! $this->permissions->canManageReportTypes($user), fn (Builder $q) => $q->where(fn (Builder $open) => $open
                ->where('owner_id', Users::id($user))
                ->orWhereIn('id', $folderIds)))
            ->orderBy('name')
            ->get();
    }
}
