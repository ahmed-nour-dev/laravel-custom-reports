<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\Report;

/**
 * Replaces a report's name, description and definition; the format follows
 * from the definition (SPEC §14.2). The type never changes.
 */
final readonly class UpdateReport
{
    public function __construct(private DefinitionValidator $validator) {}

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(Report $report, ReportDefinition $definition, array $data): Report
    {
        $format = ReportFormat::fromDefinition($definition);
        $validated = $this->validator->validate($report->reportType()->firstOrFail(), $definition, $format);

        $report->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'format' => $format,
            'definition' => $validated->definition->toArray(),
        ]);

        return $report;
    }
}
