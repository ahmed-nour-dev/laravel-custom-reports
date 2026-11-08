# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.1] - 2026-11-08

No engine changes. Upgrading from 0.1.0 needs no code changes.

### Changed

- The package is now licensed under **MIT** (previously proprietary), with a `LICENSE` file.
- The README is rewritten to cover the whole engine:
  - every report format and feature;
  - the security model;
  - a full quick start;
  - a reference table for the report definition;
  - the formula syntax;
  - an API map of every Action and Query.
- Installing is now a plain `composer require`. The private-repository and path-repository setup instructions are removed.

### Added

- GitHub Actions CI:
  - the test suite on PHP 8.3, 8.4 and 8.5 against MySQL 8.4, plus PHP 8.5 against MariaDB 11.4;
  - Pint and PHPStan (level 8).
- `CLAUDE.md` with development commands and architecture notes.

## [0.1.0] - 2026-11-08

Initial release: the admin-defined report engine, extracted from the ECC app as a standalone
Laravel 13 package (PHP 8.3+, MySQL 8 or MariaDB).

### Added

- **Report types**, built by admins over the app's models:
  - a primary object with up to three child levels, including polymorphic children;
  - lookups up to five hops deep;
  - a sectioned field layout;
  - a development → deployed lifecycle, cloning and a preview;
  - auto-adding new fields;
  - an EXPLAIN check for joins missing an index.
- **Reportable sources** (`ReportableSource`, `Field`, `ChildLink`, `Lookup`): developers declare every field, child link and lookup admins may use.
- **Four report formats**: tabular, summary, matrix and joined (up to five summary blocks merged by a shared grouping).
- **Filters**:
  - 26 operators, including relative dates;
  - AND / OR / NOT filter logic;
  - "with / without related" filters and cross filters;
  - *all / mine / my team* scopes.
- **Grouping and totals**:
  - date groupings (day to year) and buckets;
  - aggregates (`sum`, `avg`, `min`, `max`, `count_distinct`);
  - Top N, and sorting groups by a total.
- **Formulas**: summary formulas (including `PARENTGROUPVAL` and `PREVGROUPVAL`), row formulas compiled to SQL, and conditional highlighting.
- **Charts**: bar, column, stacked bar, line, pie, donut, funnel, metric and gauge, plus dashboard pins (`RunsSavedReport`).
- **Exports and runs**:
  - CSV (escaped against formula injection) and PDF exports;
  - background runs on the `reports` queue, with notifications;
  - scheduled delivery by email, daily, weekly or monthly.
- **Folders** shared with the app's groups for view or edit access.
- **Contracts for the app's own rules**: `ReportVisibility`, `ReportPermissions` and `ReportAudience`, with permissive defaults the app's bindings replace.
- **Guardrails**: statement time limits on MySQL and MariaDB, and configurable caps on columns, filters, matrix cells, detail rows and export rows.
- `ReportException`, the base of every engine failure, with an HTTP status, a stable error code and context.

[Unreleased]: https://github.com/ahmed-nour-dev/laravel-custom-reports/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/ahmed-nour-dev/laravel-custom-reports/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ahmed-nour-dev/laravel-custom-reports/releases/tag/v0.1.0
