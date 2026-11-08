<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Enums\AggregateFunction;
use AhmedNour\CustomReports\Enums\FieldType;
use AhmedNour\CustomReports\Enums\FilterOperator;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use BackedEnum;
use Illuminate\Support\Str;
use Throwable;

/**
 * The field panel of the report builder (SPEC §10): the admin's sections and
 * labels, and for each field its type, the operators and aggregates it
 * allows, and its options when it is an enum.
 */
final readonly class ReportBuilderFields
{
    public function __construct(
        private ReportTypeValidator $validator,
        private SourceRegistry $registry,
    ) {}

    /**
     * Each level with its source and effective join — the builder offers
     * "has / has no related" on the `left` ones (rule 5c).
     *
     * @return list<array{level: int, source: string, join_type: string|null}>
     */
    public function chain(ReportType $type): array
    {
        $levels = $this->validator->levelsOf($type);
        $joins = $this->validator->joinTypesOf($type);

        $chain = [];
        foreach ($levels as $level => $source) {
            $chain[] = ['level' => $level, 'source' => $source->key(), 'join_type' => ($joins[$level] ?? null)?->value];
        }

        return $chain;
    }

    /**
     * The primary source's registered children a cross filter may use (rule 6),
     * with their own fields.
     *
     * @return list<array{source: string, fields: list<array<string, mixed>>}>
     */
    public function crossFilterChildren(ReportType $type): array
    {
        $children = [];
        foreach ($this->registry->childrenOf($type->primary_source) as $edge) {
            if (isset($children[$edge->child->key()])) {
                continue;
            }

            $fields = [];
            foreach ($edge->child->fields() as $field) {
                if (! $field->isInternal()) {
                    $fields[] = [
                        'key' => $field->name,
                        'label' => Str::headline($field->name),
                        'label_ar' => null,
                        'label_key' => $field->labelKey($edge->child->key()),
                        'type' => $field->type->value,
                        'operators' => array_map(static fn (FilterOperator $op): string => $op->value, FilterOperator::for($field->type)),
                        'options' => $field->type === FieldType::ENUM && $field->enum !== null
                            ? array_map(static fn (BackedEnum $case): string => (string) $case->value, $field->enum::cases())
                            : null,
                    ];
                }
            }

            $children[$edge->child->key()] = ['source' => $edge->child->key(), 'fields' => $fields];
        }

        return array_values($children);
    }

    /**
     * @return list<array{name_en: string, name_ar: string|null, fields: list<array<string, mixed>>}>
     */
    public function handle(ReportType $type): array
    {
        $levels = $this->validator->levelsOf($type);
        $deepest = (int) array_key_last($levels);
        $fields = $type->fields()->get();

        $sections = [];
        foreach ($type->sections()->get() as $section) {
            $sectionFields = [];
            foreach ($fields->where('section_id', $section->id) as $layoutField) {
                $described = $this->field($layoutField, $levels, $deepest);
                if ($described !== null) {
                    $sectionFields[] = $described;
                }
            }

            $sections[] = ['name_en' => $section->name_en, 'name_ar' => $section->name_ar, 'fields' => $sectionFields];
        }

        return $sections;
    }

    /**
     * @param  array<int, mixed>  $levels
     * @return array<string, mixed>|null
     */
    private function field(ReportTypeField $layoutField, array $levels, int $deepest): ?array
    {
        try {
            [$ref, $source, $field] = $this->validator->field($layoutField->key, $levels);
        } catch (Throwable) {
            return null;
        }

        $repeated = $this->validator->isRepeated($ref, $deepest);

        return [
            'key' => $layoutField->key,
            'label' => $layoutField->label_en ?? Str::headline($field->name),
            'label_ar' => $layoutField->label_ar,
            'label_key' => $field->labelKey($source->key()),
            'type' => $field->type->value,
            'identity' => $field->isIdentity(),
            'repeated' => $repeated,
            'is_default' => $layoutField->is_default,
            'operators' => array_map(static fn (FilterOperator $op): string => $op->value, FilterOperator::for($field->type)),
            'aggregates' => array_values(array_map(
                static fn (AggregateFunction $fn): string => $fn->value,
                array_filter(AggregateFunction::cases(), static fn (AggregateFunction $fn): bool => (! $fn->needsNumeric() || $field->type->isNumeric())
                    && (! $repeated || $fn->allowedOnRepeated())
                    && ($fn !== AggregateFunction::COUNT_DISTINCT || $field->type !== FieldType::TEXT)),
            )),
            'options' => $field->type === FieldType::ENUM && $field->enum !== null
                ? array_map(static fn (BackedEnum $case): string => (string) $case->value, $field->enum::cases())
                : null,
        ];
    }
}
