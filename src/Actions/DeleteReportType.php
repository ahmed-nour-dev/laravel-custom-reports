<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportType;

/**
 * Soft-deletes a report type. A type that saved reports still use can't be
 * deleted: those reports would stop opening. Delete them first.
 */
final readonly class DeleteReportType
{
    public function handle(ReportType $type): void
    {
        // Joined reports name their other blocks' types by code in the definition.
        $reports = Report::query()
            ->where(fn ($uses) => $uses
                ->where('report_type_id', $type->id)
                ->orWhere(fn ($joined) => $joined->where('format', ReportFormat::JOINED->value)->whereJsonContains('definition->blocks', ['report_type' => (string) $type->code])))
            ->orderBy('name')->get(['code', 'name'])
            ->map(static fn (Report $report): array => ['code' => $report->code, 'name' => $report->name])
            ->values()->all();

        if ($reports !== []) {
            throw InvalidReportType::inUse((string) $type->code, array_values($reports));
        }

        $type->delete();
    }
}
