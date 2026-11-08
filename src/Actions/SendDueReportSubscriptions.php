<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Enums\ReportRunKind;
use AhmedNour\CustomReports\Exceptions\ReportException;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportSubscription;
use AhmedNour\CustomReports\Support\ReportAccess;
use Carbon\CarbonImmutable;

/**
 * Sends every scheduled report that is due (`reports:send-subscriptions`,
 * every five minutes). Each recipient — the subscription's users, and the
 * active members of its groups (ReportAudience) — gets **their own** background run, with
 * their visibility, and an email linking to it when it's done. Rows never go
 * in the email.
 *
 * Skipped: recipients who can't open the report (it isn't shared with
 * them), and for a table (sent as a CSV export) those without
 * `export_reports`. Then the next send is worked out.
 */
final readonly class SendDueReportSubscriptions
{
    public function __construct(
        private ReportAccess $access,
        private QueueReportRun $queue,
        private ReportAudience $audience,
        private ReportPermissions $permissions,
    ) {}

    /**
     * @return int runs queued
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $queued = 0;

        ReportSubscription::query()->whereNotNull('next_run_at')->where('next_run_at', '<=', $now)->with('report')->orderBy('id')
            ->each(function (ReportSubscription $subscription) use ($now, &$queued): void {
                $report = $subscription->report;

                if ($report instanceof Report) {
                    $queued += $this->send($subscription, $report);
                }

                $subscription->update([
                    'last_run_at' => $now,
                    'next_run_at' => $subscription->frequency->nextAfter($now, $subscription->day, $subscription->time),
                ]);
            });

        return $queued;
    }

    private function send(ReportSubscription $subscription, Report $report): int
    {
        $kind = $report->format->isGrouped() ? ReportRunKind::RUN : ReportRunKind::EXPORT_CSV;
        $queued = 0;

        $recipients = $this->audience->recipients($subscription->role_ids, $subscription->user_ids);

        foreach ($recipients as $recipient) {
            if ($this->access->forReport($report, $recipient) === null
                || ($kind->isExport() && ! $this->permissions->canExport($recipient))) {
                continue;
            }

            try {
                $this->queue->handle($report, $recipient, $kind, $subscription);
                $queued++;
            } catch (ReportException) {
                // The report no longer runs (a removed field, a format it can't send): skip, try next time.
            }
        }

        return $queued;
    }
}
