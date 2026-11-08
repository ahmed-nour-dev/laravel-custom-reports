<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

use AhmedNour\CustomReports\Builder\Formula\FormulaNode;
use AhmedNour\CustomReports\Builder\Formula\Parser;
use AhmedNour\CustomReports\Builder\Formula\RowParser;
use AhmedNour\CustomReports\Builder\Registry\Edge;
use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Contracts\ReportVisibility;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\ChartSpec;
use AhmedNour\CustomReports\Data\CrossFilter;
use AhmedNour\CustomReports\Data\FilterCondition;
use AhmedNour\CustomReports\Data\GroupSort;
use AhmedNour\CustomReports\Data\Highlight;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Data\TopN;
use AhmedNour\CustomReports\Enums\AggregateFunction;
use AhmedNour\CustomReports\Enums\ChartType;
use AhmedNour\CustomReports\Enums\FieldType;
use AhmedNour\CustomReports\Enums\FilterOperator;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Enums\ReportScope;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeField;
use BackedEnum;
use DateTimeImmutable;

/**
 * Checks a report definition against its report type (SPEC §8.1): every key
 * is in the type's layout, operators fit the field type, values fit the
 * operator and type, the logic parses and uses every filter, and the caps
 * hold. Summary reports also check groupings and aggregates (rule 4).
 */
final readonly class DefinitionValidator
{
    public const int MAX_SORTS = 3;

    public const int MAX_GROUPINGS = 3;

    /** Per axis: a matrix has up to 2 row and 2 column groupings. */
    public const int MAX_MATRIX_GROUPINGS = 2;

    private const int MAX_LIST_VALUES = 200;

    public const int MAX_CROSS_FILTER_CONDITIONS = 5;

    public const int MAX_BUCKETS = 5;

    public const int MAX_FORMULAS = 5;

    private const int MAX_BUCKET_GROUPS = 20;

    public function __construct(
        private ReportTypeValidator $types,
        private FilterLogicParser $parser,
        private SourceRegistry $registry,
        private ReportVisibility $visibility,
        private Parser $formulaParser = new Parser,
        private RowParser $rowParser = new RowParser,
    ) {}

    /** $maxFormulas: a joined report's block may hold more (SPEC §14.7). */
    public function validate(ReportType $type, ReportDefinition $definition, ReportFormat $format, int $maxFormulas = self::MAX_FORMULAS): ValidatedDefinition
    {
        $this->cap('columns', count($definition->columns), (int) config('custom-reports.max_columns', 100));
        $this->cap('filters', count($definition->filters), (int) config('custom-reports.max_filters', 20));
        $this->cap('sorts', count($definition->sort), self::MAX_SORTS);
        $this->cap('cross filters', count($definition->crossFilters), (int) config('custom-reports.max_cross_filters', 3));
        $this->cap('buckets', count($definition->buckets), self::MAX_BUCKETS);

        if ($definition->columns === [] && $format === ReportFormat::TABULAR) {
            throw InvalidReportDefinition::malformed('a tabular report needs at least one column.');
        }

        if (count($definition->columns) !== count(array_unique($definition->columns))) {
            throw InvalidReportDefinition::malformed('a column is listed twice.');
        }

        $fields = $this->resolve($type, $definition->fieldKeys());
        $rowFormula = $this->rowFormula($definition, $fields);
        $this->rowFormulaUses($definition, $rowFormula);
        $joinTypes = $this->types->joinTypesOf($type);
        $buckets = $this->buckets($definition, $fields);
        $this->bucketUses($definition, $buckets);

        $filters = [];
        $numbers = [];
        foreach ($definition->filters as $filter) {
            if (in_array($filter->n, $numbers, true)) {
                throw InvalidReportDefinition::malformed("filter number {$filter->n} is used twice.");
            }
            $numbers[] = $filter->n;
            $filters[] = $filter->compareField === null
                ? $this->filter($filter, $fields[$filter->field]->field)
                : $this->comparison($filter, $fields);
        }

        $logic = $definition->logic === null
            ? FilterLogicParser::allOf($numbers)
            : $this->parser->parse($definition->logic, $numbers);

        // RULE 5: a filter on an outer level narrows that level in ON, so it must be a top-level AND term.
        $outer = [];
        $outerNumbers = [];
        foreach ($filters as $filter) {
            $level = $fields[$filter->field]->ref->level;

            if ($filter->compareField !== null) {
                $this->comparisonLevels($filter, $fields, $joinTypes);
            }

            if (($joinTypes[$level] ?? JoinType::INNER) !== JoinType::LEFT) {
                continue;
            }

            if ($logic instanceof LogicNode && $logic->isUnderOrOrNot($filter->n)) {
                throw InvalidReportDefinition::outerFilterInOr($filter->field);
            }

            $outer[$level][] = $filter;
            $outerNumbers[] = $filter->n;
        }

        $related = [];
        foreach ($definition->without as $entry) {
            if (($joinTypes[$entry->level] ?? null) !== JoinType::LEFT) {
                throw InvalidReportDefinition::relatedOnInnerLevel($entry->level);
            }
            if (isset($related[$entry->level])) {
                throw InvalidReportDefinition::malformed("level {$entry->level} has two related filters.");
            }
            $related[$entry->level] = $entry;
        }

        $crossFilters = $this->crossFilters($type, $definition);

        if ($definition->scope !== ReportScope::ALL && ! in_array($definition->scope, $this->scopesOf($type), true)) {
            throw InvalidReportDefinition::scopeNotAvailable($definition->scope->value);
        }

        if ($format->isGrouped()) {
            $this->summary($definition, $fields, $format);
        }

        $grouped = $format->isGrouped();
        $formulas = $grouped ? $this->formulas($definition, $format, $maxFormulas) : [];
        $normalized = new ReportDefinition(
            columns: $definition->columns,
            filters: $filters,
            logic: $definition->logic,
            sort: $definition->sort,
            groupings: $grouped ? $definition->groupings : [],
            aggregates: $grouped ? $definition->aggregates : [],
            without: array_values($related),
            crossFilters: array_map(static fn (array $cross): CrossFilter => new CrossFilter(
                $cross['edge']->child->key(),
                $cross['with'],
                array_map(static fn (array $condition): FilterCondition => $condition[0], $cross['filters']),
            ), $crossFilters),
            buckets: array_values(array_map(static fn (array $bucket): Bucket => $bucket['bucket'], $buckets)),
            groupingsAcross: $format === ReportFormat::MATRIX ? $definition->groupingsAcross : [],
            sortAggregate: $grouped ? $definition->sortAggregate : null,
            topN: $grouped ? $definition->topN : null,
            hasDetailRows: $format === ReportFormat::SUMMARY && $definition->hasDetailRows,
            showSubtotals: $definition->showSubtotals,
            showGrandTotal: $definition->showGrandTotal,
            scope: $definition->scope,
            chart: $grouped ? $definition->chart : null,
            formulas: $grouped ? $definition->formulas : [],
            rowFormula: $definition->rowFormula,
            highlights: $grouped ? $definition->highlights : [],
        );

        return new ValidatedDefinition(
            definition: $normalized,
            format: $format,
            logic: $logic,
            fields: $fields,
            whereLogic: $logic?->withoutTerms($outerNumbers),
            outerFilters: $outer,
            joinTypes: $joinTypes,
            related: array_values($related),
            crossFilters: $crossFilters,
            buckets: $buckets,
            formulas: $formulas,
            rowFormula: $rowFormula,
        );
    }

    /**
     * The row formula (SPEC §14.6, 9b): it parses, reads at least one field,
     * every field is a direct field of one level (no lookups), numbers in
     * arithmetic and dates only as DATEDIFF's arguments.
     *
     * @param  array<string, ResolvedField>  $fields
     * @return array{formula: RowFormula, node: FormulaNode, level: int, repeated: bool}|null
     */
    private function rowFormula(ReportDefinition $definition, array $fields): ?array
    {
        $formula = $definition->rowFormula;

        if (! $formula instanceof RowFormula) {
            return null;
        }

        $node = $this->rowParser->parse($formula->expression);
        $keys = RowParser::fields($node);

        if ($keys === []) {
            throw InvalidReportDefinition::badRowFormula('it needs at least one field.');
        }

        $levels = [];
        foreach ($keys as $key) {
            $resolved = $fields[$key] ?? throw InvalidReportDefinition::fieldNotInLayout($key);

            if ($resolved->ref->path !== null) {
                throw InvalidReportDefinition::badRowFormula("\"{$resolved->label()}\" comes from a related record; use the object's own fields.");
            }

            $levels[$resolved->ref->level] = true;
        }

        if (count($levels) > 1) {
            throw InvalidReportDefinition::badRowFormula('all its fields must be on the same object.');
        }

        $this->rowFormulaTypes($node, $fields, inDateDiff: false);
        $first = $fields[$keys[0]];

        return ['formula' => $formula, 'node' => $node, 'level' => $first->ref->level, 'repeated' => $first->isRepeated];
    }

    /**
     * @param  array<string, ResolvedField>  $fields
     */
    private function rowFormulaTypes(FormulaNode $node, array $fields, bool $inDateDiff): void
    {
        if ($node->kind === FormulaNode::REF) {
            $resolved = $fields[(string) $node->value];
            $type = $resolved->field->type;
            $isDate = $type === FieldType::DATE || $type === FieldType::DATETIME;

            if ($inDateDiff ? ! $isDate : ! $type->isNumeric()) {
                throw InvalidReportDefinition::badRowFormula($inDateDiff
                    ? "DATEDIFF takes dates, and \"{$resolved->label()}\" isn't one."
                    : "\"{$resolved->label()}\" isn't a number, so it can't be calculated with (dates go in DATEDIFF).");
            }

            return;
        }

        $dateDiff = $node->kind === FormulaNode::CALL && $node->value === 'DATEDIFF';
        foreach ($node->children as $child) {
            $this->rowFormulaTypes($child, $fields, $dateDiff);
        }
    }

    /**
     * Where RowFormula::KEY may appear: a column, a total (sum, avg, min,
     * max — rule 4 on a repeated level) or a sort; never a grouping, filter
     * or bucket.
     *
     * @param  array{formula: RowFormula, node: FormulaNode, level: int, repeated: bool}|null  $rowFormula
     */
    private function rowFormulaUses(ReportDefinition $definition, ?array $rowFormula): void
    {
        $key = RowFormula::KEY;
        $used = [
            ...$definition->columns,
            ...array_map(static fn ($aggregate): string => $aggregate->field, $definition->aggregates),
            ...array_map(static fn ($sort): string => $sort->field, $definition->sort),
        ];

        if ($rowFormula === null && in_array($key, $used, true)) {
            throw InvalidReportDefinition::badRowFormula('the report uses it, but has none.');
        }

        $elsewhere = [
            ...array_map(static fn ($grouping): string => $grouping->field, $definition->allGroupings()),
            ...array_map(static fn ($filter): string => $filter->field, $definition->filters),
            ...array_values(array_filter(array_map(static fn ($filter): ?string => $filter->compareField, $definition->filters))),
            ...array_map(static fn ($bucket): string => $bucket->field, $definition->buckets),
        ];

        if (in_array($key, $elsewhere, true)) {
            throw InvalidReportDefinition::badRowFormula('it can be a column, a total or a sort, not a grouping, filter or bucket.');
        }

        foreach ($definition->aggregates as $aggregate) {
            if ($aggregate->field !== $key || $rowFormula === null) {
                continue;
            }
            if ($aggregate->fn === AggregateFunction::COUNT_DISTINCT) {
                throw InvalidReportDefinition::aggregateNotAllowed($key, $aggregate->fn->value, 'a row formula is summed, averaged, or its min / max taken.');
            }
            // RULE 4: a formula on a level above the deepest repeats once per deeper row.
            if ($rowFormula['repeated'] && ! $aggregate->fn->allowedOnRepeated()) {
                throw InvalidReportDefinition::aggregateOnRepeatedField($key, $aggregate->fn->value);
            }
        }
    }

    /**
     * Summary formulas (SPEC §14.6): at most MAX_FORMULAS, each parsed
     * against the report's aggregates and groupings, shown at a row /
     * column depth the report has (or everywhere).
     *
     * @return list<array{SummaryFormula, FormulaNode}>
     */
    private function formulas(ReportDefinition $definition, ReportFormat $format, int $max): array
    {
        $this->cap('formulas', count($definition->formulas), $max);
        $rows = count($definition->groupings);
        $columns = $format === ReportFormat::MATRIX ? count($definition->groupingsAcross) : 0;

        $parsed = [];
        foreach ($definition->formulas as $index => $formula) {
            if ($formula->down !== null && $formula->down >= $rows) {
                throw InvalidReportDefinition::badFormula($index, 'it is shown at a row grouping the report doesn\'t have.');
            }
            if ($formula->across !== null && $formula->across >= $columns) {
                throw InvalidReportDefinition::badFormula($index, 'it is shown at a column grouping the report doesn\'t have.');
            }

            $parsed[] = [$formula, $this->formulaParser->parse($formula->expression, $index, count($definition->aggregates), $rows, $columns)];
        }

        return $parsed;
    }

    /**
     * The scopes a report on $type may use: `mine` / `my_team` only when the
     * primary object has an owner.
     *
     * @return list<ReportScope>
     */
    public function scopesOf(ReportType $type): array
    {
        return $this->visibility->hasOwner($this->registry->source($type->primary_source)->newModel())
            ? ReportScope::cases()
            : [ReportScope::ALL];
    }

    /**
     * Keys a saved definition refers to that are no longer in the type's layout.
     *
     * @return list<string>
     */
    public function droppedKeys(ReportType $type, ReportDefinition $definition): array
    {
        $layout = $type->fields()->pluck('key')->all();

        return array_values(array_diff($definition->fieldKeys(), $layout));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ResolvedField>
     */
    private function resolve(ReportType $type, array $keys): array
    {
        $layout = $type->fields()->get()->keyBy('key');
        $levels = $this->types->levelsOf($type);
        $deepest = (int) array_key_last($levels);

        $fields = [];
        foreach ($keys as $key) {
            $layoutField = $layout->get($key);

            if (! $layoutField instanceof ReportTypeField) {
                throw InvalidReportDefinition::fieldNotInLayout($key);
            }

            try {
                [$ref, $source, $field] = $this->types->field($key, $levels);
            } catch (InvalidReportType) {
                throw InvalidReportDefinition::fieldNotInLayout($key);
            }

            $fields[$key] = new ResolvedField(
                $ref,
                $source,
                $field,
                $this->types->isRepeated($ref, $deepest),
                $layoutField->label_en,
                $layoutField->label_ar,
                $levels[$ref->level],
            );
        }

        return $fields;
    }

    private function filter(FilterCondition $filter, Field $field): FilterCondition
    {
        $type = $field->type;

        if (! in_array($filter->op, FilterOperator::for($type), true)) {
            throw InvalidReportDefinition::operatorNotAllowed($filter->field, $filter->op->value);
        }

        $value = match (true) {
            $filter->op->takesNoValue() => null,
            $filter->op->takesDayCount() => $this->dayCount($filter),
            $filter->op->takesList() => $this->list($filter, $field, min: 1, max: self::MAX_LIST_VALUES),
            $filter->op === FilterOperator::BETWEEN => $this->list($filter, $field, min: 2, max: 2),
            default => $this->scalar($filter->field, $filter->value, $field),
        };

        return new FilterCondition($filter->n, $filter->field, $filter->op, $value);
    }

    /**
     * Two fields of one row (SPEC §14.3): equals / not equals for any family,
     * the ordering operators only for numbers and dates. Both fields must be
     * the same family — number, date/datetime, text, or the same enum.
     *
     * @param  array<string, ResolvedField>  $fields
     */
    private function comparison(FilterCondition $filter, array $fields): FilterCondition
    {
        $other = (string) $filter->compareField;

        if (Bucket::isBucketKey($other)) {
            throw InvalidReportDefinition::badBucket(substr($other, strlen(Bucket::PREFIX)), 'a bucket can only be a column or a grouping.');
        }

        if ($other === $filter->field) {
            throw InvalidReportDefinition::badComparison($filter->field, 'a field can\'t be compared with itself.');
        }

        $family = $this->family($fields[$filter->field]->field);

        if ($family === null || $family !== $this->family($fields[$other]->field)) {
            throw InvalidReportDefinition::badComparison($filter->field, "\"{$other}\" is a different kind of field.");
        }

        $ordered = in_array($filter->op, [FilterOperator::LT, FilterOperator::LTE, FilterOperator::GT, FilterOperator::GTE], true);

        if (! in_array($filter->op, [FilterOperator::EQUALS, FilterOperator::NOT_EQUALS], true) && (! $ordered || ! in_array($family, ['number', 'date'], true))) {
            throw InvalidReportDefinition::operatorNotAllowed($filter->field, $filter->op->value);
        }

        return new FilterCondition($filter->n, $filter->field, $filter->op, null, $other);
    }

    /** null: the field can't be compared (booleans, users). */
    private function family(Field $field): ?string
    {
        return match (true) {
            $field->type->isNumeric() => 'number',
            $field->type === FieldType::DATE, $field->type === FieldType::DATETIME => 'date',
            $field->type === FieldType::STRING, $field->type === FieldType::TEXT => 'text',
            $field->type === FieldType::ENUM && $field->enum !== null => 'enum:'.$field->enum,
            default => null,
        };
    }

    /**
     * Rule 5 for comparisons: when either field is on an outer level, both
     * must be that level's own fields (no lookups), so the comparison can go
     * in its ON.
     *
     * @param  array<string, ResolvedField>  $fields
     * @param  array<int, JoinType>  $joinTypes
     */
    private function comparisonLevels(FilterCondition $filter, array $fields, array $joinTypes): void
    {
        $left = $fields[$filter->field]->ref;
        $right = $fields[(string) $filter->compareField]->ref;
        $outer = static fn (int $level): bool => ($joinTypes[$level] ?? JoinType::INNER) === JoinType::LEFT;

        if (($outer($left->level) || $outer($right->level))
            && ($left->level !== $right->level || $left->path !== null || $right->path !== null)) {
            throw InvalidReportDefinition::compareAcrossOuter($filter->field, (string) $filter->compareField);
        }
    }

    private function dayCount(FilterCondition $filter): int
    {
        $days = filter_var($filter->value, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > 3650) {
            throw InvalidReportDefinition::badValue($filter->field, 'expected a number of days from 1 to 3650.');
        }

        return $days;
    }

    /**
     * @return list<mixed>
     */
    private function list(FilterCondition $filter, Field $field, int $min, int $max): array
    {
        if (! is_array($filter->value) || ! array_is_list($filter->value) || count($filter->value) < $min || count($filter->value) > $max) {
            throw InvalidReportDefinition::badValue($filter->field, $min === $max ? "expected {$min} values." : "expected {$min} to {$max} values.");
        }

        return array_map(fn (mixed $value): mixed => $this->scalar($filter->field, $value, $field), $filter->value);
    }

    private function scalar(string $key, mixed $value, Field $field): mixed
    {
        return match (true) {
            $field->type === FieldType::BOOLEAN => match ($value) {
                true, 1, '1', 'true' => true,
                false, 0, '0', 'false' => false,
                default => throw InvalidReportDefinition::badValue($key, 'expected true or false.'),
            },
            $field->type->isNumeric() => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? $value + 0
                : throw InvalidReportDefinition::badValue($key, 'expected a number.'),
            $field->type === FieldType::DATE, $field->type === FieldType::DATETIME => $this->date($key, $value),
            $field->type === FieldType::ENUM => $this->enumValue($key, $value, $field->enum),
            default => is_scalar($value) && strlen((string) $value) <= 255 && (string) $value !== ''
                ? (string) $value
                : throw InvalidReportDefinition::badValue($key, 'expected text up to 255 characters.'),
        };
    }

    private function date(string $key, mixed $value): string
    {
        foreach (['!Y-m-d', '!Y-m-d H:i', '!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat($format, $value) : false;

            if ($parsed !== false && $parsed->format(ltrim($format, '!')) === $value) {
                return $value;
            }
        }

        throw InvalidReportDefinition::badValue($key, 'expected a date (YYYY-MM-DD).');
    }

    /**
     * @param  class-string<BackedEnum>|null  $enum
     */
    private function enumValue(string $key, mixed $value, ?string $enum): string|int
    {
        if ($enum === null || ! is_string($value) && ! is_int($value) || $enum::tryFrom($value) === null) {
            throw InvalidReportDefinition::badValue($key, 'not one of the allowed values.');
        }

        return $value;
    }

    /**
     * Summary and matrix reports (SPEC §8.3, §14.2): up to 3 row groupings for
     * a summary, 2 row + 2 column groupings for a matrix.
     *
     * @param  array<string, ResolvedField>  $fields
     */
    private function summary(ReportDefinition $definition, array $fields, ReportFormat $format): void
    {
        if ($format === ReportFormat::MATRIX) {
            $this->cap('row groupings', count($definition->groupings), self::MAX_MATRIX_GROUPINGS);
            $this->cap('column groupings', count($definition->groupingsAcross), self::MAX_MATRIX_GROUPINGS);

            if ($definition->groupingsAcross === []) {
                throw InvalidReportDefinition::malformed('a matrix report needs at least one column grouping.');
            }
        } else {
            $this->cap('groupings', count($definition->groupings), self::MAX_GROUPINGS);

            if ($definition->groupingsAcross !== []) {
                throw InvalidReportDefinition::malformed('column groupings need the matrix format.');
            }
        }

        $this->groupControls($definition, $format);
        $this->highlights($definition);

        foreach ($definition->allGroupings() as $grouping) {
            if (Bucket::isBucketKey($grouping->field)) {
                if ($grouping->granularity !== null) {
                    throw InvalidReportDefinition::malformed('a bucket has no date grouping.');
                }

                continue;
            }

            $type = $fields[$grouping->field]->field->type;
            $isDate = $type === FieldType::DATE || $type === FieldType::DATETIME;

            if ($grouping->granularity !== null && ! $isDate) {
                throw InvalidReportDefinition::malformed("\"{$grouping->field}\" is not a date, so it has no date grouping.");
            }
        }

        foreach ($definition->aggregates as $aggregate) {
            if ($aggregate->field === RowFormula::KEY) {
                continue; // checked in rowFormulaUses()
            }

            $resolved = $fields[$aggregate->field] ?? throw InvalidReportDefinition::fieldNotInLayout($aggregate->field);

            if ($aggregate->fn->needsNumeric() && ! $resolved->field->type->isNumeric()) {
                throw InvalidReportDefinition::aggregateNotAllowed($aggregate->field, $aggregate->fn->value, 'it needs a number field.');
            }

            // RULE 4: a field above the deepest level repeats once per deeper row.
            if ($resolved->isRepeated && ! $aggregate->fn->allowedOnRepeated()) {
                throw InvalidReportDefinition::aggregateOnRepeatedField($aggregate->field, $aggregate->fn->value);
            }

            if ($aggregate->fn === AggregateFunction::COUNT_DISTINCT && $resolved->field->type === FieldType::TEXT) {
                throw InvalidReportDefinition::aggregateNotAllowed($aggregate->field, $aggregate->fn->value, 'long text cannot be counted.');
            }
        }
    }

    /**
     * Group sort, Top-N and detail rows (SPEC §14.2): each must point at a
     * row grouping depth and an aggregate the report has.
     */
    private function groupControls(ReportDefinition $definition, ReportFormat $format): void
    {
        $aggregates = count($definition->aggregates);
        $knows = static fn (string $ref): bool => $ref === ReportDefinition::RECORD_COUNT || (int) substr($ref, 1) < $aggregates;

        if ($definition->sortAggregate instanceof GroupSort) {
            $sort = $definition->sortAggregate;
            if ($sort->depth < 0 || $sort->depth >= count($definition->groupings)) {
                throw InvalidReportDefinition::badGroupControl('sort_aggregate', 'it must sort one of the row groupings.');
            }
            if (! $knows($sort->aggregate)) {
                throw InvalidReportDefinition::badGroupControl('sort_aggregate', "the report has no aggregate {$sort->aggregate}.");
            }
        }

        if ($definition->topN instanceof TopN) {
            $top = $definition->topN;
            if ($definition->groupings === []) {
                throw InvalidReportDefinition::badGroupControl('top_n', 'it needs a row grouping.');
            }
            if ($top->limit < 1 || $top->limit > TopN::MAX_LIMIT) {
                throw InvalidReportDefinition::badGroupControl('top_n', 'the limit must be from 1 to '.TopN::MAX_LIMIT.'.');
            }
            if (! $knows($top->aggregate)) {
                throw InvalidReportDefinition::badGroupControl('top_n', "the report has no aggregate {$top->aggregate}.");
            }
        }

        if ($definition->chart instanceof ChartSpec) {
            $this->chart($definition->chart, $definition, $knows);
        }

        if ($definition->hasDetailRows) {
            if ($format !== ReportFormat::SUMMARY) {
                throw InvalidReportDefinition::badGroupControl('has_detail_rows', 'only a summary report shows detail rows.');
            }
            if ($definition->columns === []) {
                throw InvalidReportDefinition::badGroupControl('has_detail_rows', 'detail rows need at least one column.');
            }
        }
    }

    /**
     * A chart (SPEC §14.5) draws the first row grouping (`x: g0`), optionally
     * split by the second row grouping (`g1`) or the first column grouping
     * (`x0`); a matrix grouped by columns only draws its first column
     * grouping (`x: x0`). A metric or gauge draws the grand total.
     *
     * @param  callable(string): bool  $knows
     */
    private function chart(ChartSpec $chart, ReportDefinition $definition, callable $knows): void
    {
        $bad = static fn (string $reason): InvalidReportDefinition => InvalidReportDefinition::badChart($chart->type->value, $reason);

        if (! $knows($chart->value)) {
            throw $bad("the report has no aggregate {$chart->value}.");
        }

        if ($chart->type->isSingleValue()) {
            if ($chart->x !== null || $chart->series !== null) {
                throw $bad('it shows the grand total, so it takes no grouping.');
            }
            if ($chart->type === ChartType::GAUGE && ($chart->target === null || $chart->target <= 0)) {
                throw $bad('a gauge needs a target above 0.');
            }

            return;
        }

        $axis = match ($chart->x) {
            'g0' => $definition->groupings !== [],
            'x0' => $definition->groupings === [] && $definition->groupingsAcross !== [],
            default => false,
        };

        if (! $axis) {
            throw $bad('it draws the first row grouping (x: g0), or the first column grouping (x: x0) when the report has no row grouping.');
        }

        if ($chart->x === 'x0' && $chart->series !== null) {
            throw $bad('a chart of the column grouping has no second grouping to split by.');
        }

        $series = match ($chart->series) {
            null => true,
            'g1' => count($definition->groupings) >= 2,
            'x0' => $definition->groupingsAcross !== [],
            default => false,
        };

        if (! $series) {
            throw $bad('the series must be the second row grouping (g1) or the first column grouping (x0).');
        }
        if ($chart->series !== null && $chart->type->isOneSeries()) {
            throw $bad('it shows one series, so it takes no second grouping.');
        }
        if ($chart->series === null && $chart->type === ChartType::STACKED_BAR) {
            throw $bad('a stacked bar needs a second grouping to stack.');
        }
    }

    /**
     * Conditional highlighting (SPEC §14.6, 9b): at most three, each on a
     * total or summary formula the report has, once, with low ≤ high.
     */
    private function highlights(ReportDefinition $definition): void
    {
        $this->cap('highlights', count($definition->highlights), Highlight::MAX);
        $seen = [];

        foreach ($definition->highlights as $highlight) {
            $target = $highlight->target;
            $known = $target === ReportDefinition::RECORD_COUNT
                || (preg_match('/^a(\d{1,2})$/', $target, $a) === 1 && (int) $a[1] < count($definition->aggregates))
                || (preg_match('/^f(\d)$/', $target, $f) === 1 && (int) $f[1] < count($definition->formulas));

            if (! $known) {
                throw InvalidReportDefinition::badGroupControl('highlights', "the report has no total or formula {$target}.");
            }
            if (isset($seen[$target])) {
                throw InvalidReportDefinition::badGroupControl('highlights', "{$target} is highlighted twice.");
            }
            if ($highlight->low > $highlight->high) {
                throw InvalidReportDefinition::badGroupControl('highlights', 'the low breakpoint must not be above the high one.');
            }

            $seen[$target] = true;
        }
    }

    /**
     * Rule 6: each cross filter names a registered child of the primary source,
     * with at most five ANDed conditions on that child's own fields.
     *
     * @return list<array{edge: Edge, with: bool, filters: list<array{FilterCondition, Field}>}>
     */
    private function crossFilters(ReportType $type, ReportDefinition $definition): array
    {
        $children = [];
        foreach ($this->registry->childrenOf($type->primary_source) as $edge) {
            $children[$edge->child->key()] ??= $edge;
        }

        $validated = [];
        foreach ($definition->crossFilters as $cross) {
            $edge = $children[$cross->child] ?? throw InvalidReportDefinition::unknownChild($cross->child);
            $this->cap('cross filter conditions', count($cross->filters), self::MAX_CROSS_FILTER_CONDITIONS);

            $conditions = [];
            foreach ($cross->filters as $filter) {
                $field = $edge->child->field($filter->field)
                    ?? throw InvalidReportDefinition::fieldNotInLayout($cross->child.'.'.$filter->field);
                $conditions[] = [$this->filter($filter, $field), $field];
            }

            $validated[] = ['edge' => $edge, 'with' => $cross->with, 'filters' => $conditions];
        }

        return $validated;
    }

    /**
     * Buckets: numeric ranges on a number field, or groups of values on an
     * enum field (values are the enum's own cases). Every bound is checked
     * here, so the CASE expression only ever holds numbers and enum values.
     *
     * @param  array<string, ResolvedField>  $fields
     * @return array<string, array{bucket: Bucket, field: ResolvedField}>
     */
    private function buckets(ReportDefinition $definition, array $fields): array
    {
        $buckets = [];
        foreach ($definition->buckets as $bucket) {
            if (preg_match('/^[a-z0-9_]{1,20}$/', $bucket->key) !== 1 || isset($buckets[$bucket->columnKey()])) {
                throw InvalidReportDefinition::badBucket($bucket->key, 'the key must be unique, lowercase letters, digits or _.');
            }

            $resolved = $fields[$bucket->field];
            $type = $resolved->field->type;
            $groups = $bucket->groups;

            if ($groups === [] || count($groups) > self::MAX_BUCKET_GROUPS) {
                throw InvalidReportDefinition::badBucket($bucket->key, 'it needs 1 to '.self::MAX_BUCKET_GROUPS.' groups.');
            }

            foreach ($groups as $index => $group) {
                if (strlen($group['label']) > 100 || strlen($bucket->label) > 100 || strlen($bucket->other) > 100) {
                    throw InvalidReportDefinition::badBucket($bucket->key, 'labels are at most 100 characters.');
                }

                if ($type->isNumeric()) {
                    $from = $group['from'] ?? null;
                    $to = $group['to'] ?? null;
                    if (($from === null && $to === null) || ($from !== null && ! is_numeric($from)) || ($to !== null && ! is_numeric($to))
                        || ($from !== null && $to !== null && $from + 0 >= $to + 0) || isset($group['values'])) {
                        throw InvalidReportDefinition::badBucket($bucket->key, 'each group needs a numeric range (from < to).');
                    }
                    $groups[$index] = ['label' => $group['label'], 'from' => $from === null ? null : $from + 0, 'to' => $to === null ? null : $to + 0];
                } elseif ($type === FieldType::ENUM && $resolved->field->enum !== null) {
                    $values = $group['values'] ?? [];
                    $enum = $resolved->field->enum;
                    if ($values === [] || isset($group['from']) || isset($group['to'])) {
                        throw InvalidReportDefinition::badBucket($bucket->key, 'each group needs one or more values.');
                    }
                    foreach ($values as $value) {
                        if (! is_string($value) || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) !== 1 || $enum::tryFrom($value) === null) {
                            throw InvalidReportDefinition::badBucket($bucket->key, 'a value is not one of the field\'s options.');
                        }
                    }
                    $groups[$index] = ['label' => $group['label'], 'values' => array_values($values)];
                } else {
                    throw InvalidReportDefinition::badBucket($bucket->key, 'only number and picklist fields can be bucketed.');
                }
            }

            $buckets[$bucket->columnKey()] = [
                'bucket' => new Bucket($bucket->key, $bucket->field, $bucket->label, array_values($groups), $bucket->other),
                'field' => $resolved,
            ];
        }

        return $buckets;
    }

    /**
     * Bucket keys may be columns or groupings; they can't be sorted, filtered or aggregated on.
     *
     * @param  array<string, array{bucket: Bucket, field: ResolvedField}>  $buckets
     */
    private function bucketUses(ReportDefinition $definition, array $buckets): void
    {
        $used = [...$definition->columns, ...array_map(static fn ($grouping): string => $grouping->field, $definition->allGroupings())];
        foreach ($used as $key) {
            if (Bucket::isBucketKey($key) && ! isset($buckets[$key])) {
                throw InvalidReportDefinition::fieldNotInLayout($key);
            }
        }

        $elsewhere = [
            ...array_map(static fn ($sort): string => $sort->field, $definition->sort),
            ...array_map(static fn ($filter): string => $filter->field, $definition->filters),
            ...array_map(static fn ($aggregate): string => $aggregate->field, $definition->aggregates),
        ];
        foreach ($elsewhere as $key) {
            if (Bucket::isBucketKey($key)) {
                throw InvalidReportDefinition::badBucket(substr($key, strlen(Bucket::PREFIX)), 'a bucket can only be a column or a grouping.');
            }
        }
    }

    private function cap(string $what, int $count, int $max): void
    {
        if ($count > $max) {
            throw InvalidReportDefinition::tooMany($what, $count, $max);
        }
    }
}
