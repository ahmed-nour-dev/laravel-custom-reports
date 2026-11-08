<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportType;

/**
 * A saved report's definition as it runs today: minus fields removed from
 * the type since, validated against the type. Asked for as TABULAR (an
 * export), a summary or matrix gives its detail rows.
 */
final readonly class SavedReportDefinition
{
    public function __construct(private DefinitionValidator $validator) {}

    /**
     * @return array{ReportType, ValidatedDefinition}
     */
    public function handle(Report $report, ?ReportFormat $format = null): array
    {
        $type = $report->reportType()->firstOrFail();
        $definition = $report->definition();
        $definition = $definition->without($this->validator->droppedKeys($type, $definition));

        if ($format === ReportFormat::TABULAR && $report->format->isGrouped()) {
            $definition = $definition->forDetailExport();
        }

        return [$type, $this->validator->validate($type, $definition, $format ?? $this->runFormat($report, $definition))];
    }

    /**
     * The format a saved report runs as: what its definition implies now
     * (a removed column grouping turns a matrix into a summary), except that
     * a summary saved before formats were derived stays a summary even when
     * it has only a grand total left.
     */
    private function runFormat(Report $report, ReportDefinition $definition): ReportFormat
    {
        $format = ReportFormat::fromDefinition($definition);

        return $format === ReportFormat::TABULAR && $report->format === ReportFormat::SUMMARY ? ReportFormat::SUMMARY : $format;
    }
}
