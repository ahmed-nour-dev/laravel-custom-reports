<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\Report;

/** Stamps `last_run_at` without touching `updated_at`. */
final readonly class MarkReportRun
{
    public function handle(Report $report): void
    {
        $report->timestamps = false;
        $report->forceFill(['last_run_at' => now()])->save();
        $report->timestamps = true;
    }
}
