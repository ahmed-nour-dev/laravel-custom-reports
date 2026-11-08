<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Enums\FolderAccess;
use AhmedNour\CustomReports\Exceptions\ReportAccessDenied;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Models\ReportFolderShare;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Who may open or change a saved report or a folder (SPEC §8.4). Folder
 * sharing is data, checked here the way record visibility is: a report the
 * user can't open is a 404, never a hint that it exists.
 *
 * - owner: everything;
 * - the folder's owner: edit on every report filed in it, whoever saved it;
 * - a folder shared to any group the user belongs to (ReportAudience, e.g. roles): view or edit;
 * - report-type admins (ReportPermissions::canManageReportTypes): edit on everything.
 */
final class ReportAccess
{
    public const string OWNER = 'owner';

    /** @var array<int, array<int, string>> user id => folder id => access, per request */
    private array $folderAccess = [];

    public function __construct(
        private readonly ReportPermissions $permissions,
        private readonly ReportAudience $audience,
    ) {}

    /** 'owner' | 'edit' | 'view' | null */
    public function forReport(Report $report, Authenticatable $user): ?string
    {
        if ($report->owner_id === Users::id($user)) {
            return self::OWNER;
        }

        if ($this->permissions->canManageReportTypes($user)) {
            return FolderAccess::EDIT->value;
        }

        return $report->report_folder_id === null ? null : ($this->folders($user)[$report->report_folder_id] ?? null);
    }

    /** 'owner' | 'edit' | 'view' | null */
    public function forFolder(ReportFolder $folder, Authenticatable $user): ?string
    {
        if ($folder->owner_id === Users::id($user)) {
            return self::OWNER;
        }

        if ($this->permissions->canManageReportTypes($user)) {
            return FolderAccess::EDIT->value;
        }

        return $this->folders($user)[$folder->id] ?? null;
    }

    /** @throws ModelNotFoundException */
    public function assertCanOpen(Report $report, Authenticatable $user): void
    {
        if ($this->forReport($report, $user) === null) {
            throw (new ModelNotFoundException)->setModel(Report::class, [(string) $report->code]);
        }
    }

    public function assertCanEdit(Report $report, Authenticatable $user): void
    {
        $this->assertCanOpen($report, $user);

        if ($this->forReport($report, $user) === FolderAccess::VIEW->value) {
            throw ReportAccessDenied::readOnly((string) $report->code);
        }
    }

    public function assertCanOpenFolder(ReportFolder $folder, Authenticatable $user): void
    {
        if ($this->forFolder($folder, $user) === null) {
            throw (new ModelNotFoundException)->setModel(ReportFolder::class, [(string) $folder->code]);
        }
    }

    /** Saving into a folder needs edit on it. */
    public function assertCanFile(ReportFolder $folder, Authenticatable $user): void
    {
        $this->assertCanOpenFolder($folder, $user);

        if ($this->forFolder($folder, $user) === FolderAccess::VIEW->value) {
            throw ReportAccessDenied::readOnly((string) $folder->code);
        }
    }

    /** Renaming, sharing and deleting a folder is for its owner (and report-type admins). */
    public function assertManagesFolder(ReportFolder $folder, Authenticatable $user): void
    {
        $this->assertCanOpenFolder($folder, $user);

        if ($folder->owner_id !== Users::id($user) && ! $this->permissions->canManageReportTypes($user)) {
            throw ReportAccessDenied::notOwner((string) $folder->code);
        }
    }

    /**
     * Folders the user owns (edit on everything filed there) and folders shared
     * to a role they hold, with the best access any of them gives.
     *
     * @return array<int, string> folder id => 'view' | 'edit'
     */
    public function folders(Authenticatable $user): array
    {
        if (isset($this->folderAccess[Users::id($user)])) {
            return $this->folderAccess[Users::id($user)];
        }

        $access = ReportFolder::query()->where('owner_id', Users::id($user))->pluck('id')
            ->mapWithKeys(static fn (int $id): array => [$id => FolderAccess::EDIT->value])
            ->all();
        $groups = $this->audience->groupIdsOf($user);
        $shares = $groups === [] ? collect() : ReportFolderShare::query()
            ->whereIn('role_id', $groups)
            ->get(['report_folder_id', 'role_id', 'access'])
            ->groupBy('role_id');

        foreach ($shares as $roleShares) {

            foreach ($roleShares as $share) {
                /** @var ReportFolderShare $share */
                $current = $access[$share->report_folder_id] ?? null;
                if ($current !== FolderAccess::EDIT->value) {
                    $access[$share->report_folder_id] = $share->access->value;
                }
            }
        }

        return $this->folderAccess[Users::id($user)] = $access;
    }
}
