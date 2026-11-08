# laravel-custom-reports

An admin-defined report engine for Laravel. Admins build **report types** over your Eloquent
models (a primary object plus up to three child levels, with lookups). Users then build
**tabular, summary, matrix and joined reports** on them, with:

- filters with AND/OR logic, cross filters and "mine / my team" scopes
- summary formulas (`PARENTGROUPVAL`, `PREVGROUPVAL`, …) and row formulas with highlighting
- charts, dashboard pins, CSV/PDF exports, background runs and scheduled email

Your app decides who sees what. Record visibility, permissions and groups are contracts that you
bind, and the engine never assumes a roles or permissions package.

**Engine only.** The package ships Actions, Queries, models, migrations and jobs. It has no
routes, controllers or UI: your app's HTTP layer calls the Actions and Queries and shapes the
responses. The ECC app is the reference implementation (`app/Http/Controllers/CustomReport*`,
`ReportType*`, `ReportRun*`, `ReportSchedule*`).

Requires PHP 8.3+, Laravel 13, and MySQL 8 or MariaDB. The engine uses their SQL for
statement time limits, date buckets and `DATEDIFF`.

## Install

```bash
composer require ahmednour/laravel-custom-reports
php artisan vendor:publish --tag=custom-reports-config
php artisan migrate
```

The provider is auto-discovered. Background runs go to the `reports` queue, so run a worker on
it: `php artisan queue:work --queue=reports,default`.

To work against a local checkout, add a path repository:

```json
"repositories": [{ "type": "path", "url": "../laravel-custom-reports", "options": { "symlink": true } }]
```

## 1. Say what can be reported on

Each reportable model gets a `ReportableSource`, listed in `config/custom-reports.php` under
`sources`. Nothing outside that list can ever reach a report.

```php
use AhmedNour\CustomReports\Builder\Registry\{ChildLink, Field, Lookup, ReportableSource};

final class OrdersSource extends ReportableSource
{
    public function key(): string { return 'orders'; }
    public function model(): string { return Order::class; }

    public function fields(): array
    {
        return [
            Field::string('code')->identity(),   // the human handle shown instead of the id
            Field::currency('total'),
            Field::enum('status', OrderStatus::class),
            Field::date('ordered_at'),
        ];
    }

    // Orders can be a child level under customers (via the BelongsTo `customer`)…
    public function parents(): array { return [ChildLink::of(CustomersSource::class, relation: 'customer')]; }

    // …and can pull the customer's fields in as a lookup.
    public function lookups(): array { return [Lookup::to('customer', CustomersSource::class, relation: 'customer')]; }
}
```

Never expose ids or foreign keys as fields; use a lookup instead.

## 2. Plug in your rules

Bind your own implementations in a service provider. Your bindings replace the defaults.

| Contract | Decides | Default |
|---|---|---|
| `Contracts\ReportVisibility` | which records a viewer sees (`seesAll`, `constrain`), and whether "mine / my team" exist (`hasOwner`, `constrainToOwner`) | `OpenVisibility`: everything is visible and nothing has an owner, so only "all" is offered |
| `Contracts\ReportPermissions` | who manages report types, and who can export | `GatePermissions`: the Gate abilities in `custom-reports.abilities` (`manage-report-types`, `export-reports`) |
| `Contracts\ReportAudience` | the groups a user belongs to (folder sharing), and who a schedule is sent to | `ListedUsersAudience`: no groups; schedules go only to the listed users |

```php
$this->app->bind(ReportVisibility::class, MyVisibility::class);
$this->app->bind(ReportPermissions::class, MyPermissions::class);
$this->app->bind(ReportAudience::class, MyDepartments::class);
```

Visibility is applied to every level and lookup of every run, export, chart and schedule, as a
`pk IN (visible subquery)`. A viewer never sees a row your `constrain()` would hide.

The engine also offers `Contracts\RunsSavedReport`, which returns a viewer's pinned report charts
for a dashboard.

## 3. Render its errors

Every engine failure is an `Exceptions\ReportException`, carrying `status()` (an HTTP status),
`errorCode()` (a stable slug such as `report_definition_unknown_field`) and `context()`. Map it
in your exception handler, for example:

```php
$exceptions->render(fn (ReportException $e) => response()->json(
    ['code' => $e->errorCode(), 'message' => $e->getMessage(), 'context' => $e->context()],
    $e->status(),
));
```

## Configuration

`config/custom-reports.php`:

- **Data and users**
  - `sources`: the reportable sources
  - `user_model`: the users who own, run and receive reports; any Authenticatable model. Defaults to the auth provider's model.
  - `users_table`: the table the migrations' foreign keys point at
  - `audience_model`: the model `report_folder_shares.role_id` points at, for display only. The ids come from `ReportAudience`.
- **Rules and wiring**
  - `abilities`: the Gate abilities the default permissions check
  - `migrations`: set it to `false` if you publish the migrations or already have the tables
  - `run_link`: where a finished background run opens in your app
  - `disk` and `queue`: where background results are stored, and which queue runs them
- **Guardrails**
  - `max_fields`, `max_columns`, `max_filters`, `max_cross_filters`
  - `max_matrix_cells`, `max_detail_rows`
  - `export_max_rows`, `export_sync_max_rows`
  - `max_execution_ms`, `background_max_execution_ms`, `run_ttl_hours`

The guardrails can be set through `REPORTS_CUSTOM_*` env variables.

**Scheduled work.** Two Actions need calling from your scheduler, directly or through a
command of your own (ECC wraps each one in an artisan command):

```php
Schedule::call(fn () => app(\AhmedNour\CustomReports\Actions\SendDueReportSubscriptions::class)->handle())->everyMinute();
Schedule::call(fn () => app(\AhmedNour\CustomReports\Actions\PurgeReportRuns::class)->handle())->hourly();
```

The PDF layout is `custom-reports::export-pdf`. Publish it with
`--tag=custom-reports-views` to restyle it.

## Tests

```bash
composer install
DB_DATABASE=custom_reports_testing DB_USERNAME=… DB_PASSWORD=… vendor/bin/phpunit
vendor/bin/phpstan analyse   # level 8
vendor/bin/pint --test
```

The tests need a MySQL or MariaDB database, which the suite migrates fresh on every run. They
run the engine on a small fixture app (`tests/Fixtures`: users, customers, orders) with its own
visibility, Gate permissions and groups.
