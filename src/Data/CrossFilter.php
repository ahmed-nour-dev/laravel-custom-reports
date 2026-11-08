<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

/**
 * "Primary records with / without a related child" (SPEC §7.3, rule 6). The
 * child is any registered child of the primary source; its conditions use
 * the child source's own field names and are ANDed.
 */
final readonly class CrossFilter
{
    /**
     * @param  list<FilterCondition>  $filters  n is the position, field a field name of the child source
     */
    public function __construct(
        public string $child,
        public bool $with,
        public array $filters = [],
    ) {}

    /**
     * @return array{child: string, with: bool, filters: list<array{field: string, op: string, value: mixed}>}
     */
    public function toArray(): array
    {
        return [
            'child' => $this->child,
            'with' => $this->with,
            'filters' => array_map(static fn (FilterCondition $filter): array => [
                'field' => $filter->field, 'op' => $filter->op->value, 'value' => $filter->value,
            ], $this->filters),
        ];
    }
}
