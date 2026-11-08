<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Definition\JoinedValidator;
use AhmedNour\CustomReports\Data\JoinedBlock;
use AhmedNour\CustomReports\Data\JoinedDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Saves a joined report (SPEC §14.7), or replaces an existing one's blocks.
 * Every block is checked against its own type (JoinedValidator). The report
 * row points at the first block's type; the others are named, by code, in
 * the definition.
 */
final readonly class SaveJoinedReport
{
    public function __construct(private JoinedValidator $validator) {}

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(JoinedDefinition $definition, array $data, ?ReportFolder $folder, Authenticatable $owner, ?Report $existing = null): Report
    {
        $blocks = $this->validator->validate($definition, $owner);
        $normalized = new JoinedDefinition(array_map(
            static fn (array $entry): JoinedBlock => new JoinedBlock($entry['block']->reportType, $entry['block']->label, $entry['validated']->definition),
            $blocks,
        ));

        $attributes = [
            'report_type_id' => $blocks[0]['type']->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'format' => ReportFormat::JOINED,
            'definition' => $normalized->toArray(),
        ];

        if ($existing instanceof Report) {
            $existing->update($attributes);

            return $existing;
        }

        return DB::transaction(function () use ($attributes, $folder, $owner): Report {
            $report = Report::query()->create([...$attributes, 'report_folder_id' => $folder?->id, 'owner_id' => Users::id($owner)]);
            $report->update(['code' => Report::codeFromId($report->id)]);

            return $report;
        });
    }
}
