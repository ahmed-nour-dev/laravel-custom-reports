<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use AhmedNour\CustomReports\Contracts\RunsSavedReport;
use AhmedNour\CustomReports\Data\ChartSpec;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use AhmedNour\CustomReports\Exceptions\ReportTimedOut;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportPin;
use AhmedNour\CustomReports\Queries\SavedReportDefinition;
use AhmedNour\CustomReports\Queries\SummarizeCustomReport;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * RunsSavedReport: each pinned report run as the viewer through the same
 * path as opening it (SavedReportDefinition → SummarizeCustomReport), so a
 * dashboard chart and the report page always agree.
 */
final readonly class DashboardCharts implements RunsSavedReport
{
    public function __construct(
        private ReportAccess $access,
        private SavedReportDefinition $saved,
        private SummarizeCustomReport $summarize,
    ) {}

    public function dashboardCharts(Authenticatable $viewer): array
    {
        return array_values(ReportPin::query()->where('user_id', Users::id($viewer))->with('report')->orderBy('position')->orderBy('id')->get()
            ->map(fn (ReportPin $pin): array => $this->chart($pin->report, $viewer))
            ->all());
    }

    /**
     * @return array{code: string, name: string|null, available: bool, reason: string|null, chart: array<string, mixed>|null}
     */
    private function chart(?Report $report, Authenticatable $viewer): array
    {
        $missing = static fn (string $reason, ?string $code = null, ?string $name = null): array => [
            'code' => (string) $code, 'name' => $name, 'available' => false, 'reason' => $reason, 'chart' => null,
        ];

        // Deleted, or no longer shared with the viewer: say nothing about it beyond its code.
        if (! $report instanceof Report || $this->access->forReport($report, $viewer) === null) {
            return $missing('not_available', $report?->code);
        }

        try {
            [$type, $validated] = $this->saved->handle($report);

            if (! $validated->definition->chart instanceof ChartSpec || ! $validated->format->isGrouped()) {
                return $missing('no_chart', $report->code, $report->name);
            }

            $result = $this->summarize->handle($type, $validated, $viewer);
        } catch (ReportTimedOut) {
            return $missing('timed_out', $report->code, $report->name);
        } catch (InvalidReportDefinition) {
            return $missing('no_chart', $report->code, $report->name);
        }

        /** @var array<string, mixed>|null $chart */
        $chart = $result['chart'] ?? null;

        return ['code' => (string) $report->code, 'name' => $report->name, 'available' => true, 'reason' => null, 'chart' => $chart];
    }
}
