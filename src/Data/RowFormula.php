<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\FormulaType;

/**
 * The report's one row-level formula (SPEC §14.6, phase 9b): a value worked
 * out for every row from that row's own fields, e.g.
 * `l1.quantity * l1.target_retail_price`. Its operands are fields of one
 * level (no lookups); it compiles to SQL from a fixed list of templates with
 * every number bound. It is used through RowFormula::KEY, as a column, a
 * total (sum / avg / min / max) or a sort — like a field at its level, so a
 * formula on a level above the deepest repeats (rule 4).
 */
final readonly class RowFormula
{
    public const string KEY = 'formula:row';

    public const int MAX_LENGTH = 1000;

    public function __construct(
        public string $label,
        public string $expression,
        public FormulaType $type = FormulaType::NUMBER,
        public int $decimals = 2,
    ) {}

    /**
     * The field keys the expression reads (`l{N}.field`), lowercased.
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        preg_match_all('/\bl[0-3]\.[a-z_]+\b/i', $this->expression, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[0])));
    }

    /**
     * @return array{label: string, expression: string, type: string, decimals: int}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'expression' => $this->expression, 'type' => $this->type->value, 'decimals' => $this->decimals];
    }
}
