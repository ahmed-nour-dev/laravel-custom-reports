<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Support\Facades\DB;

/**
 * Auto-add new fields (SPEC §14.3), run on deploy by `reports:sync-fields`.
 * For a type with `auto_add_fields` on, fields that are new on the registry's
 * **primary** source since the last sync go to the end of a section named
 * "Added automatically". Lookup and child-level fields are never added, and
 * saved reports don't change (they only use the fields they list).
 *
 * "New" is measured against `known_fields`, so a field the admin removed
 * from the layout stays removed. Turning the option on records the current
 * fields (remember()), so only fields added after that count.
 */
final readonly class AutoAddReportTypeFields
{
    public const string SECTION_EN = 'Added automatically';

    public const string SECTION_AR = 'أضيفت تلقائيًا';

    public function __construct(
        private SourceRegistry $registry,
        private ReportTypeValidator $validator,
    ) {}

    /**
     * Every type with the option on.
     *
     * @return array<string, list<string>> type code => keys added (types with none left out)
     */
    public function syncAll(): array
    {
        $added = [];

        ReportType::query()->where('auto_add_fields', true)->orderBy('id')->each(function (ReportType $type) use (&$added): void {
            $keys = $this->handle($type);

            if ($keys !== []) {
                $added[(string) $type->code] = $keys;
            }
        });

        return $added;
    }

    /**
     * @return list<string> the layout keys added
     */
    public function handle(ReportType $type): array
    {
        if (! $type->auto_add_fields) {
            return [];
        }

        $current = $this->primaryFields($type);

        if ($type->known_fields === null) {
            $type->update(['known_fields' => $current]);

            return [];
        }

        $inLayout = $type->fields()->pluck('key')->all();
        $room = max(0, (int) config('custom-reports.max_fields', 1000) - count($inLayout));
        $keys = array_slice(array_values(array_diff(
            array_map(static fn (string $name): string => 'l0.'.$name, array_diff($current, $type->known_fields)),
            $inLayout,
        )), 0, $room);

        DB::transaction(function () use ($type, $keys, $current): void {
            if ($keys !== []) {
                $this->append($type, $keys);
            }

            $type->update(['known_fields' => $current]);
        });

        return $keys;
    }

    /** Called when the option is turned on: today's fields are the baseline. */
    public function remember(ReportType $type): void
    {
        $type->update(['known_fields' => $this->primaryFields($type)]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function append(ReportType $type, array $keys): void
    {
        $section = $type->sections()->where('name_en', self::SECTION_EN)->first()
            ?? $type->sections()->create([
                'name_en' => self::SECTION_EN,
                'name_ar' => self::SECTION_AR,
                'position' => (int) $type->sections()->max('position') + 1,
            ]);

        $levels = $this->validator->levelsOf($type);
        $deepest = (int) array_key_last($levels);
        $position = (int) $type->fields()->max('position');

        foreach ($keys as $key) {
            [$ref] = $this->validator->field($key, $levels);

            $type->fields()->create([
                'key' => $key,
                'object_level' => 0,
                'lookup_path' => null,
                'field' => $ref->field,
                'section_id' => $section->id,
                'position' => ++$position,
                'is_default' => false,
                'is_repeated' => $this->validator->isRepeated($ref, $deepest),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function primaryFields(ReportType $type): array
    {
        return array_map(static fn (Field $field): string => $field->name, $this->registry->source($type->primary_source)->fields());
    }
}
