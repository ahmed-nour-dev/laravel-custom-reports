<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\FormulaType;

/**
 * A summary formula (SPEC §14.6): an expression over the report's totals,
 * worked out in PHP from the fact map and never sent to SQL. `down` /
 * `across` pick where it shows: null for every level (the grand total
 * included), or one row / column grouping depth.
 */
final readonly class SummaryFormula
{
    public const int MAX_LENGTH = 3900;

    public const int MAX_DECIMALS = 6;

    public function __construct(
        public string $label,
        public string $expression,
        public FormulaType $type = FormulaType::NUMBER,
        public int $decimals = 2,
        public ?int $down = null,
        public ?int $across = null,
    ) {}

    /**
     * @return array{label: string, expression: string, type: string, decimals: int, down: int|null, across: int|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'expression' => $this->expression,
            'type' => $this->type->value,
            'decimals' => $this->decimals,
            'down' => $this->down,
            'across' => $this->across,
        ];
    }
}
