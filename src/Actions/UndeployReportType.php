<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/** Back to development: hidden from builders again, and the chain can change. */
final readonly class UndeployReportType
{
    public function handle(ReportType $type, Authenticatable $actor): ReportType
    {
        $type->update([
            'status' => ReportTypeStatus::DEVELOPMENT,
            'deployed_at' => null,
            'updated_by' => Users::id($actor),
        ]);

        return $type;
    }
}
