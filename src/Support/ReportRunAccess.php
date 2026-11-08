<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Exceptions\ReportRunUnavailable;
use AhmedNour\CustomReports\Models\ReportRun;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Who may see a background run (SPEC §14.4). Its rows are its viewer's, so
 * only the viewer opens the result or downloads the file. A
 * `manage_report_types` holder sees the status, never the rows. Anyone else
 * gets a 404, as if the run didn't exist.
 */
final readonly class ReportRunAccess
{
    public function __construct(private ReportPermissions $permissions) {}

    /** @throws ModelNotFoundException */
    public function assertCanSeeStatus(ReportRun $run, Authenticatable $user): void
    {
        if (! $this->isViewer($run, $user) && ! $this->permissions->canManageReportTypes($user)) {
            throw (new ModelNotFoundException)->setModel(ReportRun::class, [(string) $run->code]);
        }
    }

    public function canSeeResult(ReportRun $run, Authenticatable $user): bool
    {
        return $this->isViewer($run, $user) && $run->currentStatus() === ReportRunStatus::DONE;
    }

    /** An export's file: the viewer only, once done and until it expires. */
    public function assertCanDownload(ReportRun $run, Authenticatable $user): void
    {
        $this->assertCanSeeStatus($run, $user);

        if (! $run->kind->isExport()) {
            throw (new ModelNotFoundException)->setModel(ReportRun::class, [(string) $run->code]);
        }

        if (! $this->isViewer($run, $user)) {
            throw ReportRunUnavailable::notTheViewer((string) $run->code);
        }

        match ($run->currentStatus()) {
            ReportRunStatus::DONE => null,
            ReportRunStatus::EXPIRED => throw ReportRunUnavailable::expired((string) $run->code),
            default => throw ReportRunUnavailable::notReady((string) $run->code),
        };
    }

    private function isViewer(ReportRun $run, Authenticatable $user): bool
    {
        return $run->viewer_id === Users::id($user);
    }
}
