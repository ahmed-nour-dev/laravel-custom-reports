<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the field layout: sections in order, each with its fields in
 * order. Every key must resolve against the registry and the chain;
 * `is_repeated` is worked out here and stored for the builder (SPEC §7.4).
 */
final readonly class SaveReportTypeLayout
{
    public function __construct(private ReportTypeValidator $validator) {}

    /**
     * Dropping a field that saved reports use needs the admin's confirmation
     * ($confirmRemoval, SPEC §5); those reports then drop it the next time
     * they open, with a notice.
     *
     * @param  list<array{name_en: string, name_ar?: string|null, fields?: list<array{key: string, label_en?: string|null, label_ar?: string|null, is_default?: bool}>}>  $sections
     */
    public function handle(ReportType $type, array $sections, Authenticatable $actor, bool $confirmRemoval = false): ReportType
    {
        $levels = $this->validator->levelsOf($type);
        $deepest = (int) array_key_last($levels);

        $seen = [];
        $rows = [];
        foreach ($sections as $sectionIndex => $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($seen[$field['key']])) {
                    throw InvalidReportType::duplicateField($field['key']);
                }
                $seen[$field['key']] = true;

                [$ref] = $this->validator->field($field['key'], $levels);
                $rows[$sectionIndex][] = [
                    'key' => $field['key'],
                    'object_level' => $ref->level,
                    'lookup_path' => $ref->path,
                    'field' => $ref->field,
                    'label_en' => $field['label_en'] ?? null,
                    'label_ar' => $field['label_ar'] ?? null,
                    'is_default' => (bool) ($field['is_default'] ?? false),
                    'is_repeated' => $this->validator->isRepeated($ref, $deepest),
                ];
            }
        }

        $max = (int) config('custom-reports.max_fields', 1000);
        if (count($seen) > $max) {
            throw InvalidReportType::fieldCap(count($seen), $max);
        }

        $this->guardRemovals($type, array_keys($seen), $confirmRemoval);

        DB::transaction(function () use ($type, $sections, $rows, $actor): void {
            $type->fields()->delete();
            $type->sections()->delete();

            $position = 0;
            foreach ($sections as $sectionIndex => $section) {
                $saved = $type->sections()->create([
                    'name_en' => $section['name_en'],
                    'name_ar' => $section['name_ar'] ?? null,
                    'position' => $sectionIndex,
                ]);

                foreach ($rows[$sectionIndex] ?? [] as $row) {
                    $type->fields()->create([...$row, 'section_id' => $saved->id, 'position' => $position++]);
                }
            }

            $type->update(['updated_by' => Users::id($actor)]);
        });

        return $type->load(['sections', 'fields']);
    }

    /**
     * @param  list<string>  $keptKeys
     */
    private function guardRemovals(ReportType $type, array $keptKeys, bool $confirmed): void
    {
        $removed = array_values(array_diff($type->fields()->pluck('key')->all(), $keptKeys));

        if ($removed === [] || $confirmed) {
            return;
        }

        $affected = Report::query()->where('report_type_id', $type->id)->get()
            ->filter(static fn (Report $report): bool => array_intersect($removed, $report->definition()->fieldKeys()) !== [])
            ->map(static fn (Report $report): array => ['code' => $report->code, 'name' => $report->name])
            ->values()->all();

        if ($affected !== []) {
            throw InvalidReportType::fieldInUse(array_values($affected), $removed);
        }
    }
}
