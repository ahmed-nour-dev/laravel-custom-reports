<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * One block of a joined report (SPEC §14.7): a summary definition on its own
 * report type (by code), with a label shown over its columns.
 */
final readonly class JoinedBlock
{
    public function __construct(
        public string $reportType,
        public string $label,
        public ReportDefinition $definition,
    ) {}

    /**
     * @return array{report_type: string, label: string, definition: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['report_type' => $this->reportType, 'label' => $this->label, 'definition' => $this->definition->toArray()];
    }
}
