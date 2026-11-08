<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportSubscription;

/** Stops a report's scheduled email. Runs it already queued keep their results. */
final readonly class DeleteReportSubscription
{
    public function handle(Report $report): void
    {
        ReportSubscription::query()->where('report_id', $report->id)->delete();
    }
}
