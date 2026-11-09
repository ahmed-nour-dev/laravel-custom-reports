# laravel-custom-reports

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ahmednour/laravel-custom-reports.svg?style=flat-square)](https://packagist.org/packages/ahmednour/laravel-custom-reports)
[![Total Downloads](https://img.shields.io/packagist/dt/ahmednour/laravel-custom-reports.svg?style=flat-square)](https://packagist.org/packages/ahmednour/laravel-custom-reports)
[![Tests](https://img.shields.io/github/actions/workflow/status/ahmed-nour-dev/laravel-custom-reports/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/ahmed-nour-dev/laravel-custom-reports/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-777bb4.svg?style=flat-square)](composer.json)
[![Laravel Version](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-ff2d20.svg?style=flat-square)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

**A self-service report builder engine for Laravel.** Your admins design report types over
your Eloquent models. Your users then build, save, schedule and share their own reports
(tabular lists, grouped summaries, pivot matrices and side-by-side joined reports) with
filters, formulas, charts and exports, all without writing SQL or asking a developer.

The engine answers to your app's rules. Who sees which records, who may manage report
types, and which groups a user belongs to are contracts you bind. The engine applies them
to every query it runs and never assumes a roles or permissions package.

```php
$report = app(SaveReport::class)->handle($type, ReportDefinition::fromArray([
    'v' => 1,
    'groupings' => [['field' => 'l0.region'], ['field' => 'l1.ordered_at', 'granularity' => 'month']],
    'aggregates' => [['field' => 'l1.total', 'fn' => 'sum']],
    'formulas' => [['label' => 'Share of total', 'expression' => 'a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)', 'type' => 'percent']],
    'chart' => ['type' => 'stacked_bar', 'value' => 'a0'],
]), ['name' => 'Revenue by region and month'], $folder, $user);
```

## Contents

- [What it can do](#what-it-can-do)
- [How it stays safe](#how-it-stays-safe)
- [Install](#install)
- [Quick start](#quick-start)
- [The report definition](#the-report-definition)
- [Formulas](#formulas)
- [Background runs, exports and schedules](#background-runs-exports-and-schedules)
- [Folders, sharing and dashboards](#folders-sharing-and-dashboards)
- [API map](#api-map)
- [Configuration](#configuration)
- [Tests](#tests)
- [License](#license)

## What it can do

**Report types (built by admins, as data)**

- A primary object plus up to **3 child levels** (for example Customers → Orders → Order lines), joined inner ("with") or outer ("with or without").
- **Lookups** pull a related record's fields in (an order's customer, then that customer's account manager), up to 5 hops deep.
- **Polymorphic children** (`ChildLink::morph`) for things like activities attached to several kinds of parent.
- A field layout in sections, with default columns, a development → deployed lifecycle, cloning, a preview and an **EXPLAIN check** (`ExplainCheck`) that warns about joins missing an index.
- **Auto-add**: new fields a developer adds to the primary source can flow into existing report types (into an "Added automatically" section), without touching saved reports.

**Reports (built by users, on a deployed type)**

| Format | What it is |
|---|---|
| **Tabular** | A paged list at the grain of the deepest level, with up to 3 sort keys |
| **Summary** | Up to 3 row groupings with subtotals, a grand total and optional detail rows |
| **Matrix** | Row groupings × up to 2 column groupings (a pivot table), with totals on both axes |
| **Joined** | Up to 5 summary blocks, each on its own report type, lined up by a shared grouping |

On any format:

- **Filters**: 26 operators, including `contains`, `between`, `in`, `is_null` and relative dates (`this_quarter`, `last_n_days`, ...), combined with logic such as `1 AND (2 OR NOT 3)`.
- **"With / without" filters**: customers with no orders, or with orders matching conditions (cross filters). Outer levels support "has / has no related records".
- **Scopes**: *all*, *mine* and *my team*, offered automatically once your visibility says a model has an owner.
- **Date groupings** by day, week, month, quarter or year.
- **Buckets**: sort a field into named groups (numeric ranges or sets of enum values) and use them as columns or groupings.
- **Aggregates**: `sum`, `avg`, `min`, `max`, `count_distinct`, record counts and per-level counts.
- **Top N** groups, and sorting groups by a total.
- **Summary formulas** across groups (share of total, growth over the previous period, ...) and **row formulas** per record, with **conditional highlighting**.
- **Charts**: bar, column, stacked bar, line, pie, donut, funnel, metric and gauge (with a target).
- **Exports** to CSV and PDF, which move to the background automatically above a row threshold.
- **Background runs** with database and broadcast notifications, and **scheduled delivery** by email (daily, weekly or monthly) to users and groups.
- **Folders** shared with your groups for view or edit, and **dashboard pins** that return a user's pinned charts.

**Engine only.** The package ships Actions, Queries, models, migrations, a job and a PDF view.
It has no routes, controllers or UI: your HTTP layer calls the Actions and Queries and shapes
the responses, so it fits any front end (Inertia, Livewire, an SPA or an API).

## How it stays safe

A report builder hands users a query language, so the engine is strict:

- **Only what developers allow.** Every source, field, child link and lookup is declared in code. An admin can't name anything outside that list, and users can only use fields on the report type's layout.
- **No user text in SQL.** Definitions refer to fields by registry key (`l1.total`), never column names. Filter logic and formulas are parsed into trees and compiled from fixed templates, and every value is a bound parameter.
- **Your visibility, everywhere.** Your `ReportVisibility` rules are applied to every level and lookup of every run, export, chart and schedule, as the *runner's* visibility, never the author's. A hidden parent shows as blank cells, never a leaked value or a dropped row.
- **No hints.** A report or folder a user can't open is a 404.
- **Guardrails.** Statement time limits (MySQL `max_execution_time` / MariaDB `max_statement_time`), caps on columns, filters, matrix cells, detail rows and export rows, all configurable. A timeout becomes a clean `ReportTimedOut`, not a 500.
- **Ids stay internal.** Records show their human code (`identity()` field), and primary keys never leave the query.

## Install

Requires PHP 8.3+, Laravel 10, 11, 12 or 13, and MySQL 8 or MariaDB (the engine uses their SQL for
statement time limits, date buckets and `DATEDIFF`).

```bash
composer require ahmednour/laravel-custom-reports
php artisan vendor:publish --tag=custom-reports-config
php artisan migrate
```

The service provider is auto-discovered, so there is nothing to register. Background runs go
to the `reports` queue (`custom-reports.queue`), and so do the notifications that tell users a run finished, so run a worker on it: `php artisan queue:work --queue=reports,default`.

## Quick start

### 1. Say what can be reported on

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

Field types: `string`, `text`, `integer`, `decimal`, `currency` (optionally with a currency
column), `percent`, `date`, `datetime`, `boolean` and `enum`. Mark a field `internal()` to let
the compiler read it without ever offering it to admins.

A source can also override:

- `constrain($query, $alias)`: conditions that always apply to it.
- `route()`: where your front end opens one record, so identity cells become links.

Never expose ids or foreign keys as fields; use a lookup (`Lookup::to` by relation, or
`Lookup::byKey` by foreign key).

### 2. Build a report type

This is what your admin screens call:

```php
use AhmedNour\CustomReports\Actions\{CreateReportType, DeployReportType, SaveReportTypeLayout, UpdateReportTypeChain};
use AhmedNour\CustomReports\Enums\JoinType;

$type = app(CreateReportType::class)->handle(['name' => 'Customers with orders', 'primary_source' => 'customers'], $admin);

app(UpdateReportTypeChain::class)->handle($type, 'customers', [
    ['source' => 'orders', 'parent_link' => 'orders.customer', 'join_type' => JoinType::INNER],
], $admin);

app(SaveReportTypeLayout::class)->handle($type->refresh(), [[
    'name_en' => 'Fields',
    'fields' => [
        ['key' => 'l0.code', 'is_default' => true],
        ['key' => 'l0.region', 'is_default' => true],
        ['key' => 'l1.code', 'is_default' => true],
        ['key' => 'l1.total', 'is_default' => true],
    ],
]], $admin);

app(DeployReportType::class)->handle($type->refresh(), $admin);
```

Field keys read `l{level}[@{lookup.path}].{field}`: `l0.region` is the primary object's
field, `l1.total` is the first child level's, and `l1@customer.name` reaches through a lookup.
`SourceCatalogue` lists what an admin may choose from, and `ReportBuilderFields` lists what a
report builder may use on a type.

### 3. Run a report

```php
use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Queries\{RunCustomReport, SummarizeCustomReport};

$definition = ReportDefinition::fromArray([
    'v' => 1,
    'columns' => ['l0.code', 'l1.code', 'l1.total'],
    'filters' => [
        ['n' => 1, 'field' => 'l1.total', 'op' => 'gte', 'value' => 50],
        ['n' => 2, 'field' => 'l1.ordered_at', 'op' => 'this_quarter', 'value' => null],
    ],
    'logic' => '1 AND 2',
    'sort' => [['field' => 'l1.total', 'dir' => 'desc']],
]);

$validated = app(DefinitionValidator::class)->validate($type, $definition, ReportFormat::TABULAR);

$page = app(RunCustomReport::class)->handle($type, $validated, $viewer, page: 1, perPage: 25);
// ['columns' => [...], 'rows' => [...], 'meta' => ['current_page', 'per_page', 'total', 'last_page']]
```

Summaries and matrices go through `SummarizeCustomReport` / `RunMatrixReport` and return a
**fact map**:

- Row groups are keyed `g…` and column groups `x…`, by position (`0`, `0_1`, ...).
- Each cell is keyed `<row>!<column>`, with `T` for a total, so `$result['fact_map']['T!T']` is the grand total.
- Each cell carries its `aggregates` and `formulas`.

Joined reports go through `RunJoinedReport`.

To persist a report, use `SaveReport` / `UpdateReport` (or `SaveJoinedReport`). They
validate before saving. `SavedReportDefinition` loads a saved report as it runs today, dropping
fields removed from the type since.

### 4. Plug in your rules

Bind your own implementations in a service provider. Your bindings replace the defaults.

| Contract | Decides | Default |
|---|---|---|
| `Contracts\ReportVisibility` | which records a viewer sees (`seesAll`, `constrain`), and whether "mine / my team" exist (`hasOwner`, `constrainToOwner`) | `OpenVisibility`: everything is visible and nothing has an owner, so only "all" is offered |
| `Contracts\ReportPermissions` | who manages report types, and who can export | `GatePermissions`: the Gate abilities in `custom-reports.abilities` (`manage-report-types`, `export-reports`) |
| `Contracts\ReportAudience` | the groups a user belongs to (folder sharing), and who a schedule is sent to | `ListedUsersAudience`: no groups; schedules go only to the listed users |
| `Contracts\RunsSavedReport` | a viewer's pinned report charts, for a dashboard | `DashboardCharts` |

```php
$this->app->bind(ReportVisibility::class, MyVisibility::class);
$this->app->bind(ReportPermissions::class, MyPermissions::class);
$this->app->bind(ReportAudience::class, MyDepartments::class);
```

Visibility is applied as a `pk IN (visible subquery)` on every level and lookup. A viewer
never sees a row your `constrain()` would hide.

### 5. Render its errors

Every engine failure is an `Exceptions\ReportException`, carrying `status()` (an HTTP status),
`errorCode()` (a stable slug such as `report_definition_unknown_field`) and `context()`. Map it
in your exception handler:

```php
$exceptions->render(fn (ReportException $e) => response()->json(
    ['code' => $e->errorCode(), 'message' => $e->getMessage(), 'context' => $e->context()],
    $e->status(),
));
```

## The report definition

A saved report is a versioned JSON document (`{"v": 1, ...}`). Every key is optional.
`ReportDefinition::fromArray()` checks its shape, and `DefinitionValidator` checks it against
the report type.

| Key | Holds |
|---|---|
| `columns` | field keys (or `bucket:{key}`) to show, for tabular reports and summary detail rows |
| `filters` | `{n, field, op, value}`, where `n` is the number used in `logic` |
| `logic` | e.g. `1 AND (2 OR NOT 3)`; every filter must appear in it |
| `without` | `{level, kind: with\|without}`: "has / has no related" records on an outer level |
| `cross_filters` | `{child, with, filters}`: primary records with / without a related child matching conditions |
| `scope` | `all`, `mine` or `my_team` |
| `buckets` | `{key, field, label, groups, other}`: named ranges or value sets |
| `sort` | `{field, dir}`, up to 3 |
| `groupings` | row groupings `{field, granularity?}`, up to 3 |
| `groupings_across` | column groupings for a matrix, up to 2 |
| `aggregates` | `{field, fn}`; referenced as `a0`, `a1`, ... |
| `sort_aggregate`, `top_n` | order groups by a total; keep only the top / bottom N |
| `has_detail_rows`, `show_subtotals`, `show_grand_total` | summary display switches |
| `formulas` | summary formulas (up to 5), referenced as `f0`, `f1`, ... |
| `row_formula` | one per-row formula column |
| `highlights` | three-band colouring of a total or formula |
| `chart` | `{type, value, x?, series?, target?}` |

The format is derived from the definition (`ReportFormat::fromDefinition`): column groupings
make a matrix, row groupings or aggregates make a summary, and anything else is tabular.

## Formulas

**Summary formulas** run over the grouped results:

```
a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)      share of the grand total
(a0 - PREVGROUPVAL(a0, g1)) / PREVGROUPVAL(a0, g1)   growth over the previous month
IF(a0 > 1000, 1, 0)
```

- **Operands**: `a{i}`, `RECORD_COUNT` and numbers.
- **Operators and functions**: `+ - * /`, comparisons, `IF`, `ROUND`, `ABS`, `MIN` and `MAX`.
- **`PARENTGROUPVAL(value, g{d} | x{d} | GRAND_SUMMARY | ROW_GRAND_SUMMARY | COLUMN_GRAND_SUMMARY)`** reads a value from a parent group or grand total.
- **`PREVGROUPVAL(value, g{d} | x{d}[, n])`** reads a value from the group `n` places before.
- **Typing and placement**: a formula is typed `number`, `currency` or `percent`, and can be limited to one grouping level `down` or `across`.

**Row formulas** compute a column per record, in SQL:

```
l1.total - l1.discount
DATEDIFF(l1.shipped_at, l1.ordered_at)
IF(l1.total > 500, l1.total * 0.1, 0)
```

They support `+ - * /`, comparisons, `IF`, `COALESCE`, `ROUND`, `ABS` and `DATEDIFF` over
fields from one level.

Both are blank-safe: division by zero gives a blank, not an error.

## Background runs, exports and schedules

- **`QueueReportRun`** queues a run, a CSV export or a PDF export of a saved report for a viewer. It reuses that viewer's unfinished or unexpired run of the same definition instead of queuing a duplicate.
- **`RunCustomReportJob`** runs on the `reports` queue with the longer `background_max_execution_ms` limit and writes `report-runs/{code}.{ext}` to the configured disk.
- **`ReportRunFinished`** notifies the viewer whether the run worked or not (database and broadcast, plus mail for a finished scheduled send), linking to `run_link`. It is queued on `custom-reports.queue`, like the run.
- **`ReportRunAccess`** guards status checks and downloads.
- **Exports** (`ExportCustomReport`, `ExportJoinedReport`) return an `ExportResult`. Streamed CSV cells are escaped against formula injection. `ReportPdfExporter` renders the PDF; publish the `custom-reports::export-pdf` view with `--tag=custom-reports-views` to restyle it.
- **Schedules**: `SaveReportSubscription` sets a daily, weekly or monthly send at a time, to groups and users. Each recipient gets their own fresh run, with their own visibility, and
an email linking to it (rows never go in the email). Recipients who can't open the report are
skipped, and a tabular report goes out as a CSV export, only to recipients allowed to export.

Two Actions need calling from your scheduler, directly or through commands of your own:

```php
Schedule::call(fn () => app(\AhmedNour\CustomReports\Actions\SendDueReportSubscriptions::class)->handle())
    ->name('custom-reports:send-subscriptions')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::call(fn () => app(\AhmedNour\CustomReports\Actions\PurgeReportRuns::class)->handle())->hourly();
```

Each scheduled send goes out at most once: `SendDueReportSubscriptions` claims a due
schedule before sending it, so overlapping or retried calls never email a recipient twice,
and an error partway through a send is reported, not retried on the next tick.
`withoutOverlapping()` and `onOneServer()` (which needs a shared cache, such as Redis or the
database) are still recommended so a slow tick and the next one don't do the same work.

## Folders, sharing and dashboards

- **Folders** (`CreateReportFolder`, `RenameReportFolder`, `MoveReport`, `DeleteReportFolder`) organise saved reports.
- **Sharing** (`ShareReportFolder`) gives your groups (`ReportAudience`, for example roles or departments) view or edit access.
- **Who can do what** (`ReportAccess`):
  - Owners can do everything.
  - A folder's owner can edit every report filed in it.
  - Report-type admins can edit everything.
- **Dashboard pins** (`PinReport`, `UnpinReport`) put a report's chart on a user's dashboard. `RunsSavedReport::dashboardCharts($viewer)` returns them, each run with that viewer's visibility.

## API map

| Area | Actions | Queries |
|---|---|---|
| Report types | `CreateReportType`, `UpdateReportTypeBasics`, `UpdateReportTypeChain`, `SaveReportTypeLayout`, `AutoAddReportTypeFields`, `DeployReportType`, `UndeployReportType`, `CloneReportType`, `DeleteReportType` | `SourceCatalogue`, `ReportTypeList`, `ReportTypePicker`, `PreviewReportType`, `ReportBuilderFields` |
| Reports | `SaveReport`, `UpdateReport`, `SaveJoinedReport`, `DeleteReport`, `MarkReportRun` | `RunCustomReport`, `SummarizeCustomReport`, `RunMatrixReport`, `RunJoinedReport`, `SavedReportDefinition` |
| Exports & runs | `QueueReportRun`, `ExecuteReportRun`, `PurgeReportRuns` | `ExportCustomReport`, `ExportJoinedReport` |
| Schedules | `SaveReportSubscription`, `DeleteReportSubscription`, `SendDueReportSubscriptions` | |
| Folders & pins | `CreateReportFolder`, `RenameReportFolder`, `ShareReportFolder`, `MoveReport`, `DeleteReportFolder`, `PinReport`, `UnpinReport` | `ReportFolderQuery` |

Resolve them from the container (`app(SaveReport::class)`) or inject them.

## Configuration

`config/custom-reports.php`:

- **Data and users**
  - `sources`: the reportable sources
  - `user_model`: the users who own, run and receive reports; any Authenticatable model. Defaults to the auth provider's model.
  - `users_table`: the table the migrations' foreign keys point at
  - `audience_model`: the model `report_folder_shares.role_id` points at, for display only. The ids come from `ReportAudience`.
- **Rules and wiring**
  - `abilities`: the Gate abilities the default permissions check
  - `migrations`: set it to `false` if you publish the migrations (`--tag=custom-reports-migrations`) or already have the tables
  - `run_link`: where a finished background run opens in your app (`{code}` is the run's `RRN-####` code)
  - `disk` and `queue`: where background results are stored, and which queue runs them and sends their notifications
- **Guardrails**
  - `max_fields`, `max_columns`, `max_filters`, `max_cross_filters`
  - `max_matrix_cells`, `max_detail_rows` (hitting a cap marks the result as truncated)
  - `export_max_rows`, `export_sync_max_rows` (above which exports run in the background)
  - `max_execution_ms`, `background_max_execution_ms`, `run_ttl_hours`

The guardrails can be set through `REPORTS_CUSTOM_*` env variables.

## Tests

```bash
composer install
DB_DATABASE=custom_reports_testing DB_USERNAME=… DB_PASSWORD=… composer test
composer analyse        # PHPStan level 8
vendor/bin/pint --test
```

Unit tests need no database. Feature tests need MySQL or MariaDB, which the suite migrates
fresh on every run. They run the engine on a small fixture app (`tests/Fixtures`: users,
customers, orders) with its own visibility, Gate permissions and groups. CI runs the suite on
Laravel 10–13 across the PHP versions each supports (8.3–8.5), against MySQL 8.4 and MariaDB 11.4.

## License

MIT. See [LICENSE](LICENSE).
