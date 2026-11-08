<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use AhmedNour\CustomReports\Models\ReportTypeObject;
use AhmedNour\CustomReports\Models\ReportTypeSection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * A copy in development: the way to change a deployed type's chain without
 * changing the grain under the reports already built on it.
 */
final readonly class CloneReportType
{
    public function __construct(private CreateReportType $create) {}

    public function handle(ReportType $source, Authenticatable $actor): ReportType
    {
        return DB::transaction(function () use ($source, $actor): ReportType {
            $copy = $this->create->handle([
                'name' => 'Copy of '.$source->name,
                'description' => $source->description,
                'category' => $source->category,
                'primary_source' => $source->primary_source,
                'auto_add_fields' => $source->auto_add_fields,
            ], $actor);

            $source->objects->each(fn (ReportTypeObject $object) => $copy->objects()->create(
                $object->only(['level', 'source', 'parent_link', 'join_type']),
            ));

            $sectionIds = [];
            $source->sections()->get()->each(function (ReportTypeSection $section) use ($copy, &$sectionIds): void {
                $sectionIds[$section->id] = $copy->sections()->create($section->only(['name_en', 'name_ar', 'position']))->id;
            });

            $source->fields()->get()->each(fn (ReportTypeField $field) => $copy->fields()->create([
                ...$field->only(['key', 'object_level', 'lookup_path', 'field', 'label_en', 'label_ar', 'position', 'is_default', 'is_repeated']),
                'section_id' => $field->section_id === null ? null : ($sectionIds[$field->section_id] ?? null),
            ]));

            return $copy->load(['objects', 'sections', 'fields']);
        });
    }
}
