<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\FilterOperator;

/**
 * One numbered filter of a report definition: `{n, field, op, value}`, or
 * `{n, field, op, compare_field}` to compare two fields of the same row
 * (SPEC §14.3) — then there is no value.
 */
final readonly class FilterCondition
{
    public function __construct(
        public int $n,
        public string $field,
        public FilterOperator $op,
        public mixed $value = null,
        public ?string $compareField = null,
    ) {}

    /**
     * @return array{n: int, field: string, op: string, value: mixed, compare_field?: string}
     */
    public function toArray(): array
    {
        $array = ['n' => $this->n, 'field' => $this->field, 'op' => $this->op->value, 'value' => $this->value];

        if ($this->compareField !== null) {
            $array['compare_field'] = $this->compareField;
        }

        return $array;
    }
}
