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
use Throwable;

/**
 * Sends every scheduled report that is due. The host's scheduler calls it
 * every minute. Each recipient — the subscription's users, and the active
 * members of its groups (ReportAudience) — gets **their own** background
 * run, with their visibility, and an email linking to it when it's done.
 * Rows never go in the email.
 *
 * Each occurrence is sent **at most once**: it is claimed first, by moving
 * `next_run_at` on only if it still holds the value this run read. A second
 * scheduler that read the same row loses the claim and skips it, and an
 * error partway through the recipients never makes the next tick send it
 * again. Unexpected errors are reported, and the other subscriptions still
 * go out.
 *
 * Skipped: recipients who can't open the report (it isn't shared with
 * them), and for a table (sent as a CSV export) those without
 * `export_reports`.
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

        // By id, not by offset: claimed rows drop out of the "due" set as we go.
        ReportSubscription::query()->whereNotNull('next_run_at')->where('next_run_at', '<=', $now)->with('report')
            ->lazyById()
            ->each(function (ReportSubscription $subscription) use ($now, &$queued): void {
                if (! $this->claim($subscription, $now)) {
                    return;
                }

                $report = $subscription->report;

                if ($report instanceof Report) {
                    $queued += $this->send($subscription, $report);
                }
            });

        return $queued;
    }

    /**
     * Moves the subscription to its next send, if no one else has since it
     * was read. True when this run owns the occurrence.
     */
    private function claim(ReportSubscription $subscription, CarbonImmutable $now): bool
    {
        $changes = [
            'last_run_at' => $now,
            'next_run_at' => $subscription->frequency->nextAfter($now, $subscription->day, $subscription->time),
        ];

        $claimed = ReportSubscription::query()
            ->whereKey($subscription->id)
            ->where('next_run_at', $subscription->getRawOriginal('next_run_at'))
            ->update($changes);

        if ($claimed !== 1) {
            return false;
        }

        $subscription->forceFill($changes)->syncOriginal();

        return true;
    }

    private function send(ReportSubscription $subscription, Report $report): int
    {
        $kind = $report->format->isGrouped() ? ReportRunKind::RUN : ReportRunKind::EXPORT_CSV;
        $queued = 0;

        try {
            foreach ($this->audience->recipients($subscription->role_ids, $subscription->user_ids) as $recipient) {
                if ($this->access->forReport($report, $recipient) === null
                    || ($kind->isExport() && ! $this->permissions->canExport($recipient))) {
                    continue;
                }

                try {
                    $this->queue->handle($report, $recipient, $kind, $subscription);
                    $queued++;
                } catch (ReportException) {
                    // The report no longer runs (a removed field, a format it can't send): skip until it does.
                } catch (Throwable $e) {
                    // Report it and carry on: the other recipients still get theirs.
                    report($e);
                }
            }
        } catch (Throwable $e) {
            // The app's audience failed: this occurrence is over, the next one tries again.
            report($e);
        }

        return $queued;
    }
}
