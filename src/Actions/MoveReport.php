<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportFolder;

/** Into a folder, or back to private (null). */
final readonly class MoveReport
{
    public function handle(Report $report, ?ReportFolder $folder): Report
    {
        $report->update(['report_folder_id' => $folder?->id]);

        return $report;
    }
}
