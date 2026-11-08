<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Saves a new report for its owner, private or in a folder (validated against
 * the type first). The format follows from the definition (SPEC §14.2).
 */
final readonly class SaveReport
{
    public function __construct(private DefinitionValidator $validator) {}

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(ReportType $type, ReportDefinition $definition, array $data, ?ReportFolder $folder, Authenticatable $owner): Report
    {
        $format = ReportFormat::fromDefinition($definition);
        $validated = $this->validator->validate($type, $definition, $format);

        return DB::transaction(function () use ($type, $format, $validated, $data, $folder, $owner): Report {
            $report = Report::query()->create([
                'report_type_id' => $type->id,
                'report_folder_id' => $folder?->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'format' => $format,
                'definition' => $validated->definition->toArray(),
                'owner_id' => Users::id($owner),
            ]);

            $report->update(['code' => Report::codeFromId($report->id)]);

            return $report;
        });
    }
}
