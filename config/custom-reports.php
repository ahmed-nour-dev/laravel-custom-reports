<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Custom reports
    |--------------------------------------------------------------------------
    |
    | What the engine reports on, who its users are, and its guardrails.
    | Record visibility, permissions and groups are the app's: bind your own
    | AhmedNour\CustomReports\Contracts\ReportVisibility, ReportPermissions
    | and ReportAudience (the defaults are permissive: everything visible,
    | Gate abilities below, no groups).
    |
    */

    // Every object admins may report on: classes extending
    // AhmedNour\CustomReports\Builder\Registry\ReportableSource.
    'sources' => [],

    // The users who own, run and receive reports (null: the auth provider's model).
    'user_model' => null,

    // The table that model lives in, for the migrations' foreign keys.
    'users_table' => 'users',

    // The groups folders are shared with (report_folder_shares.role_id), e.g. a Role model.
    // Null: folders are not shared with groups.
    'audience_model' => null,

    // Gate abilities the default ReportPermissions checks.
    'abilities' => [
        'manage_report_types' => 'manage-report-types',
        'export' => 'export-reports',
    ],

    // Load the package migrations (turn off once they are published or the tables exist).
    'migrations' => true,

    // Where a finished background run opens in the app ({code} = RRN-####).
    'run_link' => '/reports/runs/{code}',

    // The disk background results are written to.
    'disk' => 'local',

    // The queue background runs go to.
    'queue' => env('REPORTS_CUSTOM_QUEUE', 'reports'),

    // Fields an admin may expose on one report type (layout editor cap).
    'max_fields' => (int) env('REPORTS_CUSTOM_MAX_FIELDS', 1000),

    // Columns a saved report may select.
    'max_columns' => (int) env('REPORTS_CUSTOM_MAX_COLUMNS', 100),

    // Standard filters per report (every one must appear in the filter logic).
    'max_filters' => (int) env('REPORTS_CUSTOM_MAX_FILTERS', 20),

    // Cross filters per report ("with / without related X"), ANDed.
    'max_cross_filters' => (int) env('REPORTS_CUSTOM_MAX_CROSS_FILTERS', 3),

    // Hard row cap for PDF / CSV export.
    'export_max_rows' => (int) env('REPORTS_CUSTOM_EXPORT_MAX_ROWS', 50000),

    // Summary / matrix cells a run returns at most; hitting it sets meta.truncated.
    'max_matrix_cells' => (int) env('REPORTS_CUSTOM_MAX_MATRIX_CELLS', 10000),

    // Detail rows under the groups of a summary report, at most.
    'max_detail_rows' => (int) env('REPORTS_CUSTOM_MAX_DETAIL_ROWS', 2000),

    // MySQL / MariaDB statement time limit on run queries, in milliseconds.
    'max_execution_ms' => (int) env('REPORTS_CUSTOM_MAX_EXECUTION_MS', 30000),

    // Exports above this many rows run in the background.
    'export_sync_max_rows' => (int) env('REPORTS_CUSTOM_EXPORT_SYNC_MAX_ROWS', 5000),

    // The statement time limit for background runs, in milliseconds.
    'background_max_execution_ms' => (int) env('REPORTS_CUSTOM_BACKGROUND_MAX_EXECUTION_MS', 300000),

    // Hours a background result is kept.
    'run_ttl_hours' => (int) env('REPORTS_CUSTOM_RUN_TTL_HOURS', 24),

];
