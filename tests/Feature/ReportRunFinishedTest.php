<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Feature;

use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Models\ReportRun;
use AhmedNour\CustomReports\Notifications\ReportRunFinished;
use AhmedNour\CustomReports\Tests\Fixtures\User;
use AhmedNour\CustomReports\Tests\TestCase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;

/**
 * The "run finished" notification is queued where the run was: on
 * `custom-reports.queue`, not the connection's default queue, which an app's
 * workers may not listen to.
 */
final class ReportRunFinishedTest extends TestCase
{
    public function test_it_is_queued_on_the_configured_report_queue(): void
    {
        config(['custom-reports.queue' => 'report-jobs']);
        Queue::fake();

        $viewer = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
        $viewer->notify(new ReportRunFinished($this->run()));

        Queue::assertPushedOn('report-jobs', SendQueuedNotifications::class);
    }

    public function test_it_uses_the_package_default_queue_when_not_configured(): void
    {
        $this->assertSame('reports', (new ReportRunFinished($this->run()))->queue);
    }

    private function run(): ReportRun
    {
        return (new ReportRun)->forceFill(['code' => 'RRN-0001', 'status' => ReportRunStatus::DONE]);
    }
}
