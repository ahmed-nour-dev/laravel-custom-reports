<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportFolder;
use Illuminate\Support\Facades\DB;

/** Deletes a folder; its reports go back to private for their owners. */
final readonly class DeleteReportFolder
{
    public function handle(ReportFolder $folder): void
    {
        DB::transaction(function () use ($folder): void {
            $folder->reports()->withTrashed()->update(['report_folder_id' => null]);
            $folder->delete();
        });
    }
}
