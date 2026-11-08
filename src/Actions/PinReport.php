<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Data\ChartSpec;
use AhmedNour\CustomReports\Exceptions\ReportPinRefused;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportPin;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Pins a saved report's chart to $user's dashboard (SPEC §14.5), last in
 * order. Only a report with a chart, and at most MAX per user. The caller
 * has checked the user may open the report. Pinning twice is a no-op.
 */
final readonly class PinReport
{
    public const int MAX = 6;

    public function handle(Report $report, Authenticatable $user): ReportPin
    {
        $existing = ReportPin::query()->where('user_id', Users::id($user))->where('report_id', $report->id)->first();

        if ($existing instanceof ReportPin) {
            return $existing;
        }

        if (! $report->definition()->chart instanceof ChartSpec) {
            throw ReportPinRefused::noChart((string) $report->code);
        }

        $count = ReportPin::query()->where('user_id', Users::id($user))->count();

        if ($count >= self::MAX) {
            throw ReportPinRefused::tooMany(self::MAX);
        }

        return ReportPin::query()->create(['user_id' => Users::id($user), 'report_id' => $report->id, 'position' => $count]);
    }
}
