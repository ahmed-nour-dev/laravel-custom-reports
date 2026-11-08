<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use AhmedNour\CustomReports\Models\ReportTypeObject;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the primary object and the A → B → C → D chain. Rejects an inner
 * level after an outer one (rule 1) rather than silently cascading it, so the
 * admin sees what they get. Only allowed in development, and only while no
 * saved report uses the type: the chain is the row grain, and saved reports
 * store their fields by level. Re-saving the same chain is a no-op.
 *
 * Layout fields that no longer resolve against the new chain are dropped and
 * `is_repeated` is recomputed for the rest.
 */
final readonly class UpdateReportTypeChain
{
    public function __construct(private ReportTypeValidator $validator) {}

    /**
     * @param  list<array{source: string, parent_link: string, join_type: JoinType}>  $objects
     */
    public function handle(ReportType $type, string $primarySource, array $objects, Authenticatable $actor): ReportType
    {
        if ($type->status === ReportTypeStatus::DEPLOYED) {
            throw InvalidReportType::chainLockedWhenDeployed((string) $type->code);
        }

        $levels = $this->validator->chain($primarySource, $objects);

        if ($this->unchanged($type, $primarySource, $objects)) {
            return $type->load('objects');
        }

        $this->guardSavedReports($type);

        $deepest = (int) array_key_last($levels);

        DB::transaction(function () use ($type, $primarySource, $objects, $levels, $deepest, $actor): void {
            $type->update(['primary_source' => $primarySource, 'updated_by' => Users::id($actor)]);
            $type->objects()->delete();

            foreach ($objects as $index => $object) {
                $type->objects()->create([
                    'level' => $index + 1,
                    'source' => $object['source'],
                    'parent_link' => $object['parent_link'],
                    'join_type' => $object['join_type'],
                ]);
            }

            $type->fields()->get()->each(function (ReportTypeField $field) use ($levels, $deepest): void {
                try {
                    [$ref] = $this->validator->field($field->key, $levels);
                } catch (InvalidReportType) {
                    $field->delete();

                    return;
                }

                $field->update(['is_repeated' => $this->validator->isRepeated($ref, $deepest)]);
            });
        });

        return $type->load('objects');
    }

    /**
     * @param  list<array{source: string, parent_link: string, join_type: JoinType}>  $objects
     */
    private function unchanged(ReportType $type, string $primarySource, array $objects): bool
    {
        $current = $type->objects()->orderBy('level')->get()
            ->map(static fn (ReportTypeObject $object): array => [$object->source, $object->parent_link, $object->join_type])
            ->all();
        $wanted = array_map(static fn (array $object): array => [$object['source'], $object['parent_link'], $object['join_type']], $objects);

        return $type->primary_source === $primarySource && $current === $wanted;
    }

    private function guardSavedReports(ReportType $type): void
    {
        $reports = Report::query()->where('report_type_id', $type->id)->orderBy('name')->get(['code', 'name'])
            ->map(static fn (Report $report): array => ['code' => $report->code, 'name' => $report->name])
            ->values()->all();

        if ($reports !== []) {
            throw InvalidReportType::chainInUse((string) $type->code, array_values($reports));
        }
    }
}
