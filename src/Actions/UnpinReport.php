<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportPin;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/** Takes a report's chart off $user's dashboard. Not pinned is not an error. */
final readonly class UnpinReport
{
    public function handle(Report $report, Authenticatable $user): void
    {
        ReportPin::query()->where('user_id', Users::id($user))->where('report_id', $report->id)->delete();
    }
}
