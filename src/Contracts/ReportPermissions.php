<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The two permissions the engine itself asks about. Everything else — who
 * may open the builder, run, save — is the app's routing and middleware.
 */
interface ReportPermissions
{
    /** Report-type admins: build and deploy types, see types in development, edit any report. */
    public function canManageReportTypes(Authenticatable $user): bool;

    /** May export (CSV / PDF), and so receive a scheduled table as a CSV. */
    public function canExport(Authenticatable $user): bool;
}
