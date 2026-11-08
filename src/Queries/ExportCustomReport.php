<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Compiler\CompiledQuery;
use AhmedNour\CustomReports\Builder\Compiler\Guardrail;
use AhmedNour\CustomReports\Builder\Compiler\SelectedColumn;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\ExportResult;
use AhmedNour\CustomReports\Exceptions\ReportTimedOut;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\CsvCell;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Every row of a tabular custom report as the viewer sees it, capped at
 * `custom-reports.export_max_rows`, for PDF (an ExportResult for the
 * existing ReportPdfExporter) or CSV (a row stream). Exports above
 * `export_sync_max_rows` run in the background (needsBackground(),
 * writeCsv()).
 */
final readonly class ExportCustomReport
{
    public function __construct(
        private RunCustomReport $run,
        private Guardrail $guardrail,
    ) {}

    public function toExportResult(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, string $title): ExportResult
    {
        [$columns, $rows] = $this->rows($type, $validated, $viewer);

        return new ExportResult(
            title: $title,
            columns: $columns,
            rows: iterator_to_array($rows, false),
        );
    }

    /**
     * The header labels and a stream of flat rows (identity cells become their code).
     *
     * @return array{array<string, string>, Generator<int, array<string, mixed>>}
     */
    public function rows(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): array
    {
        [$compiled, $columns, $labels] = $this->prepare($type, $validated, $viewer);
        $compiled->query->limit($this->cap());

        $stream = (function () use ($compiled, $columns): Generator {
            // Buffered: the whole capped result is read under the time limit.
            $rows = $this->guardrail->run($compiled->query->getConnection(), fn () => $compiled->query->get());

            foreach ($rows as $row) {
                yield $this->flat($row, $columns);
            }
        })();

        return [$labels, $stream];
    }

    /**
     * Rows the export holds before the cap — or null when even counting hits
     * the time limit.
     */
    public function count(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): ?int
    {
        [$compiled] = $this->run->compile($type, $validated, $viewer);
        $count = $compiled->query->newQuery()->fromSub(clone $compiled->query, 't');

        try {
            return $this->guardrail->run($compiled->query->getConnection(), fn (): int => $count->count());
        } catch (ReportTimedOut) {
            return null;
        }
    }

    /**
     * Whether an export should run in the background (SPEC §14.4): more rows
     * than `export_sync_max_rows`, or too slow even to count.
     */
    public function needsBackground(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): bool
    {
        $count = $this->count($type, $validated, $viewer);

        return $count === null || $count > max(0, (int) config('custom-reports.export_sync_max_rows', 5000));
    }

    /**
     * A background CSV export: rows read 1,000 at a time and written straight
     * to $handle, up to the cap, so memory stays flat whatever the size.
     *
     * @param  resource  $handle
     * @return array{int, bool} rows written, and whether the cap cut some off
     */
    public function writeCsv(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, $handle): array
    {
        [$compiled, $columns, $labels] = $this->prepare($type, $validated, $viewer);
        $cap = $this->cap();

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(CsvCell::safe(...), array_values($labels)), escape: '\\');

        return $this->guardrail->run($compiled->query->getConnection(), function () use ($compiled, $columns, $cap, $handle): array {
            $written = 0;

            foreach ($compiled->query->lazy(1000) as $row) {
                if ($written === $cap) {
                    return [$written, true];
                }

                fputcsv($handle, array_map(CsvCell::safe(...), array_values($this->flat($row, $columns))), escape: '\\');
                $written++;
            }

            return [$written, false];
        });
    }

    /**
     * The compiled, ordered query, its columns and their header labels.
     *
     * @return array{CompiledQuery, list<SelectedColumn>, array<string, string>}
     */
    private function prepare(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer): array
    {
        [$compiled, $columns] = $this->run->compile($type, $validated, $viewer);
        $this->run->ordered($compiled, $validated);

        $labels = [];
        foreach ($columns as $column) {
            $labels[$column->alias] = $column->bucket instanceof Bucket ? $column->bucket->label : $validated->label($column->key());
        }

        return [$compiled, $columns, $labels];
    }

    /**
     * @param  list<SelectedColumn>  $columns
     * @return array<string, mixed>
     */
    private function flat(object $row, array $columns): array
    {
        return array_map(static fn (mixed $cell): mixed => match (true) {
            is_array($cell) => $cell['code'] ?? null,
            is_bool($cell) => $cell ? 'Yes' : 'No',
            default => $cell,
        }, $this->run->row($row, $columns));
    }

    public function cap(): int
    {
        return max(1, (int) config('custom-reports.export_max_rows', 50000));
    }
}
