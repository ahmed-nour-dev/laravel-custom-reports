<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportRun;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes expired runs and their files (scheduled hourly as
 * `reports:purge-runs`). A run expires `run_ttl_hours` after it finished,
 * done or failed; unfinished runs are left alone.
 */
final readonly class PurgeReportRuns
{
    /**
     * @return int runs deleted
     */
    public function handle(): int
    {
        $deleted = 0;

        ReportRun::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->orderBy('id')
            ->each(function (ReportRun $run) use (&$deleted): void {
                if ($run->result_path !== null) {
                    Storage::disk(ExecuteReportRun::disk())->delete($run->result_path);
                }

                $run->delete();
                $deleted++;
            });

        return $deleted;
    }
}
