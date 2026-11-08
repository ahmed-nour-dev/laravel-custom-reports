<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support\Defaults;

use AhmedNour\CustomReports\Contracts\ReportPermissions;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default permissions: Laravel Gate abilities, named in
 * `custom-reports.abilities` (`manage_report_types`, `export`).
 */
final readonly class GatePermissions implements ReportPermissions
{
    public function __construct(private Gate $gate) {}

    public function canManageReportTypes(Authenticatable $user): bool
    {
        return $this->gate->forUser($user)->allows((string) config('custom-reports.abilities.manage_report_types', 'manage-report-types'));
    }

    public function canExport(Authenticatable $user): bool
    {
        return $this->gate->forUser($user)->allows((string) config('custom-reports.abilities.export', 'export-reports'));
    }
}
