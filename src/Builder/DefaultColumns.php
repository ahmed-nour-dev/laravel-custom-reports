<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder;

use AhmedNour\CustomReports\Builder\Compiler\Alias;
use AhmedNour\CustomReports\Builder\Compiler\CompiledQuery;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use Illuminate\Support\Collection;

/**
 * The columns a report type opens with: its layout's default fields, or —
 * while the layout has none — the first few fields of each level.
 */
final class DefaultColumns
{
    private const int FALLBACK_FIELDS_PER_LEVEL = 3;

    /**
     * @return Collection<string, ReportTypeField> key => layout field
     */
    public function layout(ReportType $type): Collection
    {
        return $type->fields()->where('is_default', true)->get()->keyBy('key');
    }

    /**
     * @return list<string>
     */
    public function keys(ReportType $type, CompiledQuery $compiled): array
    {
        $layout = $this->layout($type);

        if ($layout->isNotEmpty()) {
            return array_values(array_map(strval(...), $layout->keys()->all()));
        }

        $keys = [];
        foreach ($compiled->levels as $level => $source) {
            $names = array_map(
                static fn (Field $field): string => $field->name,
                array_values(array_filter($source->fields(), static fn (Field $field): bool => ! $field->isInternal())),
            );

            foreach (array_slice($names, 0, self::FALLBACK_FIELDS_PER_LEVEL) as $name) {
                $keys[] = Alias::level($level).'.'.$name;
            }
        }

        return $keys;
    }
}
