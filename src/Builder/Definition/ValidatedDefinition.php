<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

use AhmedNour\CustomReports\Builder\Formula\FormulaNode;
use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\FilterCondition;
use AhmedNour\CustomReports\Data\RelatedFilter;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Enums\ReportFormat;

/** A definition that passed DefinitionValidator, with its fields resolved and its logic parsed. */
final readonly class ValidatedDefinition
{
    /**
     * @param  array<string, ResolvedField>  $fields  key => field, for every key the definition uses
     * @param  ?LogicNode  $whereLogic  the logic without the outer-scoped filters (they go in ON)
     * @param  array<int, list<FilterCondition>>  $outerFilters  level => filters compiled into that level's ON (rule 5a)
     * @param  array<int, JoinType>  $joinTypes  effective join per child level
     * @param  list<RelatedFilter>  $related
     * @param  list<array{edge: Edge, with: bool, filters: list<array{FilterCondition, Field}>}>  $crossFilters
     * @param  array<string, array{bucket: Bucket, field: ResolvedField}>  $buckets  column key (`bucket:x`) => bucket
     * @param  list<array{SummaryFormula, FormulaNode}>  $formulas  each summary formula, parsed
     * @param  array{formula: RowFormula, node: FormulaNode, level: int, repeated: bool}|null  $rowFormula  the row formula, parsed and placed
     */
    public function __construct(
        public ReportDefinition $definition,
        public ReportFormat $format,
        public ?LogicNode $logic,
        public array $fields,
        public ?LogicNode $whereLogic = null,
        public array $outerFilters = [],
        public array $joinTypes = [],
        public array $related = [],
        public array $crossFilters = [],
        public array $buckets = [],
        public array $formulas = [],
        public ?array $rowFormula = null,
    ) {}

    public function isBucket(string $key): bool
    {
        return isset($this->buckets[$key]);
    }

    public function field(string $key): ResolvedField
    {
        return $this->fields[$key];
    }

    /** A field's label, or the row formula's for RowFormula::KEY. */
    public function label(string $key): string
    {
        return $key === RowFormula::KEY && $this->rowFormula !== null ? $this->rowFormula['formula']->label : $this->field($key)->label();
    }

    /**
     * How a key is described to the SPA: a field, or the row formula (type
     * `formula`, with its own format and decimals).
     *
     * @return array<string, mixed>
     */
    public function describe(string $key): array
    {
        if ($key !== RowFormula::KEY || $this->rowFormula === null) {
            return $this->field($key)->describe();
        }

        $formula = $this->rowFormula['formula'];

        return [
            'key' => RowFormula::KEY,
            'label' => $formula->label,
            'label_ar' => null,
            'label_key' => null,
            'type' => 'formula',
            'formula_type' => $formula->type->value,
            'decimals' => $formula->decimals,
            'repeated' => $this->rowFormula['repeated'],
            'identity' => false,
        ];
    }
}
