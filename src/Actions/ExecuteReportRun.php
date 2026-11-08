<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportRunKind;
use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Exceptions\ReportException;
use AhmedNour\CustomReports\Models\ReportRun;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Notifications\ReportRunFinished;
use AhmedNour\CustomReports\Queries\ExportCustomReport;
use AhmedNour\CustomReports\Queries\SummarizeCustomReport;
use AhmedNour\CustomReports\Services\ReportPdfExporter;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Runs a queued report run (RunCustomReportJob) as its viewer and writes the
 * result to `report-runs/{code}.{ext}` on the local disk: a summary or
 * matrix as JSON, an export as CSV (streamed) or PDF. Queries get
 * `background_max_execution_ms` instead of the interactive limit. Then the
 * viewer is told, whether it worked or not.
 */
final readonly class ExecuteReportRun
{
    /** The default disk; `custom-reports.disk` wins. */
    public const string DISK = 'local';

    public static function disk(): string
    {
        return (string) config('custom-reports.disk', self::DISK);
    }

    public function __construct(
        private DefinitionValidator $validator,
        private SummarizeCustomReport $summarize,
        private ExportCustomReport $export,
        private ReportPdfExporter $pdf,
    ) {}

    public function handle(ReportRun $run): void
    {
        if ($run->status !== ReportRunStatus::QUEUED) {
            return;
        }

        $run->update(['status' => ReportRunStatus::RUNNING, 'started_at' => now()]);
        $ttl = now()->addHours(max(1, (int) config('custom-reports.run_ttl_hours', 24)));

        try {
            [$path, $rows, $truncated] = $this->withBackgroundLimit(fn (): array => $this->execute($run));

            $run->update([
                'status' => ReportRunStatus::DONE,
                'result_path' => $path,
                'row_count' => $rows,
                'truncated' => $truncated,
                'finished_at' => now(),
                'expires_at' => $ttl,
            ]);
        } catch (Throwable $e) {
            if (! $e instanceof ReportException) {
                report($e);
            }

            // A half-written export is no use to anyone.
            Storage::disk(self::disk())->delete(self::path($run));

            $run->update([
                'status' => ReportRunStatus::FAILED,
                'error_slug' => $e instanceof ReportException ? $e->errorCode() : 'report_run_failed',
                'finished_at' => now(),
                'expires_at' => $ttl,
            ]);
        }

        // The app's user model gets the notification when it can take one (Notifiable).
        $viewer = $run->viewer()->first();
        if ($viewer !== null && method_exists($viewer, 'notify')) {
            $viewer->notify(new ReportRunFinished($run->refresh()));
        }
    }

    /**
     * @return array{string, int, bool} the result path, its rows, and whether a cap cut some off
     */
    private function execute(ReportRun $run): array
    {
        $type = $run->reportType()->firstOrFail();
        $viewer = $run->viewer()->firstOrFail();
        if (! $viewer instanceof Authenticatable) {
            throw new RuntimeException('The configured user model must be Authenticatable.');
        }
        $validated = $this->validator->validate($type, ReportDefinition::fromArray($run->definition), $run->format);
        $path = self::path($run);
        $disk = Storage::disk(self::disk());

        return match ($run->kind) {
            ReportRunKind::RUN => $this->summary($type, $validated, $viewer, $path),
            ReportRunKind::EXPORT_CSV => $this->csv($type, $validated, $viewer, $path),
            ReportRunKind::EXPORT_PDF => (function () use ($type, $validated, $viewer, $run, $path, $disk): array {
                $result = $this->export->toExportResult($type, $validated, $viewer, $run->report->name ?? $type->name);
                $disk->put($path, $this->pdf->output($result));
                $rows = count($result->rows);

                return [$path, $rows, $rows >= $this->export->cap()];
            })(),
        };
    }

    /**
     * @return array{string, int, bool}
     */
    private function summary(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, string $path): array
    {
        $result = $this->summarize->handle($type, $validated, $viewer);
        Storage::disk(self::disk())->put($path, json_encode($result, JSON_THROW_ON_ERROR));

        /** @var array{meta?: array{truncated?: bool}, fact_map?: array<string, array{record_count?: int}>} $result */
        $total = $result['fact_map'][FactMap::TOTAL.'!'.FactMap::TOTAL]['record_count'] ?? 0;

        return [$path, (int) $total, (bool) ($result['meta']['truncated'] ?? false)];
    }

    /**
     * @return array{string, int, bool}
     */
    private function csv(ReportType $type, ValidatedDefinition $validated, Authenticatable $viewer, string $path): array
    {
        $disk = Storage::disk(self::disk());
        $disk->makeDirectory('report-runs');
        $handle = fopen($disk->path($path), 'wb');

        if ($handle === false) {
            throw new RuntimeException("Can't write {$path}.");
        }

        try {
            [$rows, $truncated] = $this->export->writeCsv($type, $validated, $viewer, $handle);
        } finally {
            fclose($handle);
        }

        return [$path, $rows, $truncated];
    }

    public static function path(ReportRun $run): string
    {
        return 'report-runs/'.$run->code.'.'.$run->kind->extension();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function withBackgroundLimit(Closure $run): mixed
    {
        $previous = config('custom-reports.max_execution_ms');
        config(['custom-reports.max_execution_ms' => (int) config('custom-reports.background_max_execution_ms', 300000)]);

        try {
            return $run();
        } finally {
            config(['custom-reports.max_execution_ms' => $previous]);
        }
    }
}
