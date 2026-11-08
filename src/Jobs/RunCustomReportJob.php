<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Jobs;

use AhmedNour\CustomReports\Actions\ExecuteReportRun;
use AhmedNour\CustomReports\Models\ReportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one queued report run on the `reports` queue. Not retried: a run
 * that failed records why, and the viewer starts a new one.
 */
class RunCustomReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $runId)
    {
        $this->onQueue((string) config('custom-reports.queue', 'reports'));
        $this->afterCommit();
    }

    public function handle(ExecuteReportRun $execute): void
    {
        $run = ReportRun::query()->find($this->runId);

        if ($run instanceof ReportRun) {
            $execute->handle($run);
        }
    }
}
