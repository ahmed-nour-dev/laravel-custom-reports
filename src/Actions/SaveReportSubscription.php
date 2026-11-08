<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Enums\SubscriptionFrequency;
use AhmedNour\CustomReports\Exceptions\ReportSubscriptionInvalid;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportSubscription;
use AhmedNour\CustomReports\Support\Users;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Creates or replaces a report's schedule (SPEC §14.5): how often, when, and
 * who gets it (roles and users, already resolved from codes by the caller).
 * The next send is worked out from now. One schedule per report; the
 * caller has checked the actor may edit the report.
 */
final readonly class SaveReportSubscription
{
    public const int MAX_RECIPIENTS = 50;

    /**
     * @param  list<int>  $roleIds
     * @param  list<int>  $userIds
     */
    public function handle(Report $report, SubscriptionFrequency $frequency, ?int $day, string $time, array $roleIds, array $userIds, Authenticatable $actor): ReportSubscription
    {
        if ($report->format === ReportFormat::JOINED) {
            throw ReportSubscriptionInvalid::joined();
        }

        $days = $frequency->days();

        if ($days === null ? $day !== null : ! in_array($day, $days, true)) {
            throw ReportSubscriptionInvalid::badDay($frequency->value);
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw ReportSubscriptionInvalid::badTime();
        }

        $roleIds = array_values(array_unique($roleIds));
        $userIds = array_values(array_unique($userIds));
        $count = count($roleIds) + count($userIds);

        if ($count === 0 || $count > self::MAX_RECIPIENTS) {
            throw ReportSubscriptionInvalid::recipients(self::MAX_RECIPIENTS);
        }

        return DB::transaction(function () use ($report, $frequency, $day, $time, $roleIds, $userIds, $actor): ReportSubscription {
            $subscription = ReportSubscription::query()->updateOrCreate(['report_id' => $report->id], [
                'owner_id' => Users::id($actor),
                'frequency' => $frequency,
                'day' => $day,
                'time' => $time,
                'role_ids' => $roleIds,
                'user_ids' => $userIds,
                'next_run_at' => $frequency->nextAfter(CarbonImmutable::now(), $day, $time),
            ]);

            if ($subscription->code === null) {
                $subscription->update(['code' => ReportSubscription::codeFromId($subscription->id)]);
            }

            return $subscription;
        });
    }
}
