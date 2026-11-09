# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ahmednour/laravel-custom-reports`: a Laravel package (Laravel 10–13, PHP 8.3+) holding an admin-defined report engine. It ships Actions, Queries, models, migrations, a job and a PDF view only: **no routes, controllers or UI**. The host app's HTTP layer calls the Actions/Queries. The "ECC app" mentioned in docblocks is the original host app the engine was extracted from; it is not in this repo.

Docblocks cite `SPEC §x.y`. The spec is not in this repo, so treat the docblocks themselves as the spec.

## Commands

```bash
composer test                     # vendor/bin/phpunit
composer analyse                  # PHPStan (Larastan) level 8 over src/ and database/
composer format                   # Pint (default Laravel preset, no pint.json)
vendor/bin/pint --test            # what CI runs

vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit tests/Unit/Formula/FormulaTest.php
vendor/bin/phpunit --filter test_name_here
```

- **Unit tests** (`tests/Unit`) extend `PHPUnit\Framework\TestCase` and need no database.
- **Feature tests** (`tests/Feature`, built on `tests/TestCase.php`, Orchestra Testbench) need a real MySQL 8 or MariaDB. SQLite is not supported because the engine uses MySQL SQL (statement time limits, date buckets, `DATEDIFF`). Set `DB_CONNECTION` (`mysql`/`mariadb`), `DB_HOST`, `DB_PORT`, `DB_DATABASE` (default `custom_reports_testing`), `DB_USERNAME` and `DB_PASSWORD`. The suite uses `RefreshDatabase`.
- Feature tests run against a small fixture app in `tests/Fixtures` (User, Customer, Order, their `ReportableSource`s, `OwnerVisibility`, `FixedAudience`, and its own migrations).
- CI (`.github/workflows/ci.yml`) runs the tests on Laravel 10–13 × PHP 8.3/8.4/8.5 (skipping pairs Laravel does not support: 10 on 8.4+, 11 on 8.5) with MySQL 8.4, plus Laravel 13 on PHP 8.5 with MariaDB 11.4, and runs Pint and PHPStan (on Laravel 13).
- **Supporting Laravel 10–13:** do not use framework APIs newer than Laravel 10 in `src/` or `tests/` without a fallback. Examples: `#[UseFactory]` (12+, so models override `newFactory()` instead), `casts()` methods (11+, use `$casts`), the `Exceptions` facade (11+), and the `mariadb` driver name (11+; detect MariaDB with `MySqlConnection::isMaria()`).

## Architecture

### Two kinds of configuration: developer and admin

1. **Developer (code):** each reportable model gets a `Builder/Registry/ReportableSource` subclass listed in `config('custom-reports.sources')`. It declares `fields()`, `parents()` (`ChildLink`, which says which sources it can be a child level under) and `lookups()` (`Lookup`, BelongsTo-style pulls of another source's fields). `SourceRegistry` (bound as **scoped**, so one per request or job) indexes them and inverts `parents()` into a parent → children graph.
2. **Admin (data):** a `ReportType` model is a primary source plus up to 3 child levels (`ReportTypeObject`) and a field layout (`ReportTypeSection`/`ReportTypeField`). Its status is `development` or `deployed`. `ReportTypeValidator` checks every save against the registry, so an admin can never name a source, link, lookup or field the code did not declare.

Users then save `Report`s on a deployed type. The `definition` is a versioned JSON (`{"v": 1, ...}`) in one of four formats: tabular, summary, matrix or joined.

### Field keys, never column names

Everything in a definition refers to fields by registry key: `l{level}[@{lookup.path}].{field}`, for example `l1.total` or `l0@lead.lead_source.name` (see `Compiler/FieldRef`). SQL aliases come from those keys: level N is `l{N}`, and lookup path `l{N}@a.b` is `l{N}__a__b` (`Compiler/Alias`). No user input ever reaches SQL as an identifier, and formula numbers are bound parameters. Never expose ids or foreign keys as fields; use a lookup.

### The run pipeline

1. `Data/ReportDefinition::fromArray()` (or `JoinedDefinition`) checks **shape only**.
2. `Builder/Definition/DefinitionValidator` checks it **against the report type** (keys in the layout, operator vs field type, filter logic uses every filter, caps) and returns a `ValidatedDefinition`. `Queries/SavedReportDefinition` re-validates a saved report as it runs today, dropping fields removed from the type since.
3. `Builder/Compiler/*` builds the query:
   - `BaseQueryCompiler`: the FROM/JOIN skeleton at the grain of the deepest level.
   - `ScopeApplier`: the **only** implicit scopes, which are app visibility, soft deletes and the source's `constrain()`. Visibility is always `{alias}.pk IN (visible subquery)`, placed in `ON` (not `WHERE`) for joined levels so outer joins keep their rows.
   - `JoinRegistry`: lookups, always LEFT JOINed hop by hop, with visibility in `ON`. A hidden parent shows as blank cells, never as a dropped row.
   - `Select`, `Filter`, `CrossFilter`, `ScopeFilter`, `RowFormula` and `Aggregate` compilers handle the rest.
   - `Guardrail` wraps execution in a session statement time limit (MySQL `max_execution_time` in ms, MariaDB `max_statement_time` in seconds, detected from the server, not the driver) and turns a timeout into `ReportTimedOut`.
4. Queries run it: `RunCustomReport` (tabular, paged), `SummarizeCustomReport`/`RunMatrixReport` (grouped, via `AggregateCompiler`), `RunJoinedReport` (each block summarized on its own type, then merged by the shared grouping), and `ExportCustomReport`/`ExportJoinedReport`.
5. Summary and matrix results become a **fact map** (`Builder/FactMap`): row groups `g{i}` and column groups `x{i}` keyed by index path (`0`, `0_1`), cells keyed `<rowpath>!<colpath>`, with `T` for a total. Joined reports prefix keys with `B{n}:`. Keys hold positions, never record values.

Reports always run with the **runner's** visibility, never the author's.

### Two formula languages

- **Summary formulas** (`Formula/Tokenizer` → `Parser` → `Evaluator`) are evaluated **in PHP** over the fact map after SQL runs. They reference `a{i}`, `RECORD_COUNT`, `g{d}`/`x{d}`, `PARENTGROUPVAL` and `PREVGROUPVAL`. Nulls propagate, and division by zero gives null.
- **Row formulas** (`Formula/RowParser` → `Compiler/RowFormulaCompiler`) compile **to SQL**, with each node mapped to a fixed template and division written as `a / nullif(b, 0)`.

Both parsers must fail only with `InvalidReportDefinition` on bad input, never with another error. The grammars are in each parser's docblock.

### Host-app contracts

The provider binds defaults with `bindIf`, so the app's own bindings win:

| Contract | Default |
|---|---|
| `ReportVisibility` (`seesAll`, `constrain`, `hasOwner`, `constrainToOwner`) | `OpenVisibility` |
| `ReportPermissions` | `GatePermissions` (abilities from `custom-reports.abilities`) |
| `ReportAudience` (groups for folder sharing, schedule recipients) | `ListedUsersAudience` |
| `RunsSavedReport` (dashboard pinned charts) | `DashboardCharts` |

The engine must not assume any roles or permissions package. The user model is configurable (`custom-reports.user_model`); use `Support/Users` rather than referencing a concrete User class.

### Conventions

- Writes are single-purpose `final readonly` classes in `src/Actions` with a `handle()` method. Reads are the same shape in `src/Queries`. Value objects are in `src/Data`.
- Every deliberate failure extends `Exceptions/ReportException`, carrying `status()` (HTTP), a stable `errorCode()` slug and `context()`. Keep error codes stable, since host apps render them.
- Access to saved reports and folders goes through `Support/ReportAccess`. A report the user cannot open is a 404, never a hint that it exists.
- Background runs: `QueueReportRun` → `Jobs/RunCustomReportJob` (on the `reports` queue) → `ExecuteReportRun`, which writes `report-runs/{code}.{ext}` to the configured disk with `background_max_execution_ms`, then sends `ReportRunFinished`. The host's scheduler must call `SendDueReportSubscriptions` (every minute) and `PurgeReportRuns` (hourly).
- Guardrail caps (`max_columns`, `max_filters`, `max_matrix_cells`, `export_*`, ...) live in `config/custom-reports.php` and can be overridden with `REPORTS_CUSTOM_*` env vars.
- Files use `declare(strict_types=1);`, and models record their columns as `@property` docblocks for Larastan.
