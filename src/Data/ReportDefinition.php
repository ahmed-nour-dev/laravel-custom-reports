<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Enums\AggregateFunction;
use AhmedNour\CustomReports\Enums\ChartType;
use AhmedNour\CustomReports\Enums\DateGranularity;
use AhmedNour\CustomReports\Enums\FilterOperator;
use AhmedNour\CustomReports\Enums\FormulaType;
use AhmedNour\CustomReports\Enums\ReportScope;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * A saved report's definition (SPEC §8.1), versioned `{"v": 1, …}`. Every
 * field reference is a registry key from the type's layout, never a column
 * name. fromArray() checks the shape only; DefinitionValidator checks it
 * against the report type.
 */
final readonly class ReportDefinition
{
    public const int VERSION = 1;

    /** The group-control reference to the record count, next to `a{i}`. */
    public const string RECORD_COUNT = 'record_count';

    /**
     * @param  list<string>  $columns
     * @param  list<FilterCondition>  $filters
     * @param  list<SortOrder>  $sort
     * @param  list<Grouping>  $groupings
     * @param  list<AggregateSpec>  $aggregates
     * @param  list<RelatedFilter>  $without  "has / has no related B" per outer level
     * @param  list<CrossFilter>  $crossFilters
     * @param  list<Bucket>  $buckets
     * @param  list<Grouping>  $groupingsAcross  matrix column groupings ($groupings are the rows)
     */
    public function __construct(
        public array $columns = [],
        public array $filters = [],
        public ?string $logic = null,
        public array $sort = [],
        public array $groupings = [],
        public array $aggregates = [],
        public array $without = [],
        public array $crossFilters = [],
        public array $buckets = [],
        public array $groupingsAcross = [],
        public ?GroupSort $sortAggregate = null,
        public ?TopN $topN = null,
        public bool $hasDetailRows = false,
        public bool $showSubtotals = true,
        public bool $showGrandTotal = true,
        public ReportScope $scope = ReportScope::ALL,
        public ?ChartSpec $chart = null,
        /** @var list<SummaryFormula> */
        public array $formulas = [],
        public ?RowFormula $rowFormula = null,
        /** @var list<Highlight> */
        public array $highlights = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['v'] ?? self::VERSION;
        if ($version !== self::VERSION) {
            throw InvalidReportDefinition::unsupportedVersion($version);
        }

        return new self(
            columns: array_map(static fn (mixed $key): string => self::string($key, 'columns'), self::list($data, 'columns')),
            filters: array_map(static function (mixed $filter): FilterCondition {
                if (! is_array($filter)) {
                    throw InvalidReportDefinition::malformed('each filter must be an object.');
                }
                $op = FilterOperator::tryFrom(self::string($filter['op'] ?? null, 'filters.op'))
                    ?? throw InvalidReportDefinition::malformed('unknown filter operator.');
                $n = $filter['n'] ?? null;
                if (! is_int($n) || $n < 1 || $n > 99) {
                    throw InvalidReportDefinition::malformed('each filter needs a number from 1 to 99.');
                }

                $compare = isset($filter['compare_field']) ? self::string($filter['compare_field'], 'filters.compare_field') : null;

                return new FilterCondition($n, self::string($filter['field'] ?? null, 'filters.field'), $op, $compare === null ? $filter['value'] ?? null : null, $compare);
            }, self::list($data, 'filters')),
            logic: isset($data['logic']) && $data['logic'] !== '' ? self::string($data['logic'], 'logic') : null,
            sort: array_map(static function (mixed $sort): SortOrder {
                if (! is_array($sort)) {
                    throw InvalidReportDefinition::malformed('each sort must be an object.');
                }
                $dir = strtolower(self::string($sort['dir'] ?? 'asc', 'sort.dir'));

                return new SortOrder(self::string($sort['field'] ?? null, 'sort.field'), $dir === 'desc' ? 'desc' : 'asc');
            }, self::list($data, 'sort')),
            groupings: array_map(self::grouping(...), self::list($data, 'groupings')),
            aggregates: array_map(static function (mixed $aggregate): AggregateSpec {
                if (! is_array($aggregate)) {
                    throw InvalidReportDefinition::malformed('each aggregate must be an object.');
                }

                return new AggregateSpec(
                    self::string($aggregate['field'] ?? null, 'aggregates.field'),
                    AggregateFunction::tryFrom(self::string($aggregate['fn'] ?? null, 'aggregates.fn'))
                        ?? throw InvalidReportDefinition::malformed('unknown aggregate function.'),
                );
            }, self::list($data, 'aggregates')),
            without: array_map(static function (mixed $related): RelatedFilter {
                $level = is_array($related) ? ($related['level'] ?? null) : null;
                $kind = is_array($related) ? ($related['kind'] ?? null) : null;
                if (! is_int($level) || ! in_array($kind, ['with', 'without'], true)) {
                    throw InvalidReportDefinition::malformed('each related filter needs a level and kind "with" or "without".');
                }

                return new RelatedFilter($level, $kind === 'with');
            }, self::list($data, 'without')),
            crossFilters: array_map(static function (mixed $cross): CrossFilter {
                if (! is_array($cross) || ! is_bool($cross['with'] ?? null)) {
                    throw InvalidReportDefinition::malformed('each cross filter needs a child and "with": true or false.');
                }
                $filters = [];
                foreach (self::list($cross, 'filters') as $index => $filter) {
                    if (! is_array($filter)) {
                        throw InvalidReportDefinition::malformed('each cross filter condition must be an object.');
                    }
                    $filters[] = new FilterCondition(
                        $index + 1,
                        self::string($filter['field'] ?? null, 'cross_filters.filters.field'),
                        FilterOperator::tryFrom(self::string($filter['op'] ?? null, 'cross_filters.filters.op'))
                            ?? throw InvalidReportDefinition::malformed('unknown filter operator.'),
                        $filter['value'] ?? null,
                    );
                }

                return new CrossFilter(self::string($cross['child'] ?? null, 'cross_filters.child'), $cross['with'], $filters);
            }, self::list($data, 'cross_filters')),
            buckets: array_map(static function (mixed $bucket): Bucket {
                if (! is_array($bucket)) {
                    throw InvalidReportDefinition::malformed('each bucket must be an object.');
                }
                $groups = [];
                foreach (self::list($bucket, 'groups') as $group) {
                    if (! is_array($group)) {
                        throw InvalidReportDefinition::malformed('each bucket group must be an object.');
                    }
                    $groups[] = array_filter([
                        'label' => self::string($group['label'] ?? null, 'buckets.groups.label'),
                        'from' => $group['from'] ?? null,
                        'to' => $group['to'] ?? null,
                        'values' => isset($group['values']) ? array_values((array) $group['values']) : null,
                    ], static fn (mixed $value): bool => $value !== null);
                }

                return new Bucket(
                    self::string($bucket['key'] ?? null, 'buckets.key'),
                    self::string($bucket['field'] ?? null, 'buckets.field'),
                    self::string($bucket['label'] ?? null, 'buckets.label'),
                    $groups,
                    isset($bucket['other']) && $bucket['other'] !== '' ? self::string($bucket['other'], 'buckets.other') : 'Other',
                );
            }, self::list($data, 'buckets')),
            groupingsAcross: array_map(self::grouping(...), self::list($data, 'groupings_across')),
            sortAggregate: self::groupSort($data['sort_aggregate'] ?? null),
            topN: self::topN($data['top_n'] ?? null),
            hasDetailRows: self::bool($data, 'has_detail_rows', false),
            showSubtotals: self::bool($data, 'show_subtotals', true),
            showGrandTotal: self::bool($data, 'show_grand_total', true),
            scope: ReportScope::tryFrom(isset($data['scope']) ? self::string($data['scope'], 'scope') : ReportScope::ALL->value)
                ?? throw InvalidReportDefinition::malformed('"scope" must be all, mine or my_team.'),
            chart: self::chart($data['chart'] ?? null),
            formulas: array_map(self::formula(...), self::list($data, 'formulas')),
            rowFormula: self::rowFormula($data['row_formula'] ?? null),
            highlights: array_map(self::highlight(...), self::list($data, 'highlights')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'columns' => $this->columns,
            'filters' => array_map(static fn (FilterCondition $filter): array => $filter->toArray(), $this->filters),
            'logic' => $this->logic,
            'without' => array_map(static fn (RelatedFilter $related): array => $related->toArray(), $this->without),
            'cross_filters' => array_map(static fn (CrossFilter $cross): array => $cross->toArray(), $this->crossFilters),
            'buckets' => array_map(static fn (Bucket $bucket): array => $bucket->toArray(), $this->buckets),
            'sort' => array_map(static fn (SortOrder $sort): array => $sort->toArray(), $this->sort),
            'groupings' => array_map(static fn (Grouping $grouping): array => $grouping->toArray(), $this->groupings),
            'groupings_across' => array_map(static fn (Grouping $grouping): array => $grouping->toArray(), $this->groupingsAcross),
            'aggregates' => array_map(static fn (AggregateSpec $aggregate): array => $aggregate->toArray(), $this->aggregates),
            'sort_aggregate' => $this->sortAggregate?->toArray(),
            'top_n' => $this->topN?->toArray(),
            'has_detail_rows' => $this->hasDetailRows,
            'show_subtotals' => $this->showSubtotals,
            'show_grand_total' => $this->showGrandTotal,
            'scope' => $this->scope->value,
            'chart' => $this->chart?->toArray(),
            'formulas' => array_map(static fn (SummaryFormula $formula): array => $formula->toArray(), $this->formulas),
            'row_formula' => $this->rowFormula?->toArray(),
            'highlights' => array_map(static fn (Highlight $highlight): array => $highlight->toArray(), $this->highlights),
        ];
    }

    /**
     * Every grouping, rows first, then columns.
     *
     * @return list<Grouping>
     */
    public function allGroupings(): array
    {
        return [...$this->groupings, ...$this->groupingsAcross];
    }

    /**
     * Every field key the definition refers to.
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->columns,
            ...array_map(static fn (FilterCondition $filter): string => $filter->field, $this->filters),
            ...array_values(array_filter(array_map(static fn (FilterCondition $filter): ?string => $filter->compareField, $this->filters))),
            ...array_map(static fn (SortOrder $sort): string => $sort->field, $this->sort),
            ...array_map(static fn (Grouping $grouping): string => $grouping->field, $this->allGroupings()),
            ...array_map(static fn (AggregateSpec $aggregate): string => $aggregate->field, $this->aggregates),
            ...array_map(static fn (Bucket $bucket): string => $bucket->field, $this->buckets),
            ...($this->rowFormula?->fieldKeys() ?? []),
        ], static fn (string $key): bool => ! Bucket::isBucketKey($key) && $key !== RowFormula::KEY)));
    }

    /**
     * The same definition without any reference to $keys — what a saved
     * report keeps after an admin removed those fields from the type. A
     * removed filter also drops out of custom logic, which falls back to AND.
     *
     * @param  list<string>  $keys
     */
    public function without(array $keys): self
    {
        $buckets = array_values(array_filter($this->buckets, static fn (Bucket $bucket): bool => ! in_array($bucket->field, $keys, true)));
        // A row formula reading a removed field goes, and everything that used it.
        $rowFormula = $this->rowFormula instanceof RowFormula && array_intersect($this->rowFormula->fieldKeys(), $keys) !== [] ? null : $this->rowFormula;
        $removed = [...$keys, ...(! $rowFormula instanceof RowFormula && $this->rowFormula instanceof RowFormula ? [RowFormula::KEY] : []), ...array_map(
            static fn (Bucket $bucket): string => $bucket->columnKey(),
            array_filter($this->buckets, static fn (Bucket $bucket): bool => in_array($bucket->field, $keys, true)),
        )];
        $keep = static fn (string $key): bool => ! in_array($key, $removed, true);
        $filters = array_values(array_filter($this->filters, static fn (FilterCondition $filter): bool => $keep($filter->field)
            && ($filter->compareField === null || $keep($filter->compareField))));
        $columns = array_values(array_filter($this->columns, $keep));

        // Group controls point at groupings by depth and at aggregates by
        // index, so they follow what is left — or go when their target went.
        $keptDepths = array_keys(array_filter($this->groupings, static fn (Grouping $grouping): bool => $keep($grouping->field)));
        $keptAggregates = array_keys(array_filter($this->aggregates, static fn (AggregateSpec $aggregate): bool => $keep($aggregate->field)));
        $aggregate = static function (string $ref) use ($keptAggregates): ?string {
            if ($ref === self::RECORD_COUNT) {
                return $ref;
            }
            $index = array_search((int) substr($ref, 1), $keptAggregates, true);

            return $index === false ? null : 'a'.$index;
        };

        $intact = count($keptDepths) === count($this->groupings) && count($keptAggregates) === count($this->aggregates)
            && count(array_filter($this->groupingsAcross, static fn (Grouping $grouping): bool => $keep($grouping->field))) === count($this->groupingsAcross);

        $sortAggregate = null;
        if ($this->sortAggregate instanceof GroupSort) {
            $depth = array_search($this->sortAggregate->depth, $keptDepths, true);
            $ref = $aggregate($this->sortAggregate->aggregate);
            $sortAggregate = $depth === false || $ref === null ? null : new GroupSort($depth, $ref, $this->sortAggregate->dir);
        }

        $topN = null;
        if ($this->topN instanceof TopN && ($keptDepths[0] ?? null) === 0) {
            $ref = $aggregate($this->topN->aggregate);
            $topN = $ref === null ? null : new TopN($this->topN->limit, $ref, $this->topN->dir);
        }

        return new self(
            columns: $columns,
            filters: $filters,
            logic: count($filters) === count($this->filters) ? $this->logic : null,
            sort: array_values(array_filter($this->sort, static fn (SortOrder $sort): bool => $keep($sort->field))),
            groupings: array_values(array_filter($this->groupings, static fn (Grouping $grouping): bool => $keep($grouping->field))),
            aggregates: array_values(array_filter($this->aggregates, static fn (AggregateSpec $aggregate): bool => $keep($aggregate->field))),
            without: $this->without,
            crossFilters: $this->crossFilters,
            buckets: $buckets,
            groupingsAcross: array_values(array_filter($this->groupingsAcross, static fn (Grouping $grouping): bool => $keep($grouping->field))),
            sortAggregate: $sortAggregate,
            topN: $topN,
            hasDetailRows: $this->hasDetailRows && $columns !== [],
            showSubtotals: $this->showSubtotals,
            showGrandTotal: $this->showGrandTotal,
            scope: $this->scope,
            // The chart and formulas point at groupings and aggregates by position: if any went, they go too.
            chart: $intact ? $this->chart : null,
            formulas: $intact ? $this->formulas : [],
            rowFormula: $rowFormula,
            highlights: $intact ? $this->highlights : [],
        );
    }

    /**
     * The detail rows a summary report exports (PLAN phase 3c): its own
     * columns when it has some, otherwise what it groups by and totals,
     * ordered by the groupings so rows of one group sit together.
     */
    public function forDetailExport(): self
    {
        if ($this->columns !== []) {
            return $this;
        }

        $grouped = array_map(static fn (Grouping $grouping): string => $grouping->field, $this->allGroupings());
        $columns = array_values(array_unique([
            ...$grouped,
            ...array_map(static fn (AggregateSpec $aggregate): string => $aggregate->field, $this->aggregates),
        ]));
        $sort = $this->sort !== [] ? $this->sort : array_map(
            static fn (string $field): SortOrder => new SortOrder($field),
            array_values(array_filter($grouped, static fn (string $field): bool => ! str_starts_with($field, Bucket::PREFIX))),
        );

        return new self(
            columns: $columns,
            filters: $this->filters,
            logic: $this->logic,
            sort: $sort,
            without: $this->without,
            crossFilters: $this->crossFilters,
            buckets: $this->buckets,
            scope: $this->scope,
            rowFormula: $this->rowFormula,
        );
    }

    private static function grouping(mixed $grouping): Grouping
    {
        if (! is_array($grouping)) {
            throw InvalidReportDefinition::malformed('each grouping must be an object.');
        }
        $granularity = $grouping['granularity'] ?? null;

        return new Grouping(
            self::string($grouping['field'] ?? null, 'groupings.field'),
            $granularity === null ? null : (DateGranularity::tryFrom(self::string($granularity, 'groupings.granularity'))
                ?? throw InvalidReportDefinition::malformed('unknown date granularity.')),
        );
    }

    private static function formula(mixed $data): SummaryFormula
    {
        if (! is_array($data)) {
            throw InvalidReportDefinition::malformed('each formula must be an object.');
        }

        $label = self::string($data['label'] ?? null, 'formulas.label');
        $expression = $data['expression'] ?? null;
        if (! is_string($expression) || trim($expression) === '' || strlen($expression) > SummaryFormula::MAX_LENGTH) {
            throw InvalidReportDefinition::malformed('each formula needs an expression of at most '.SummaryFormula::MAX_LENGTH.' characters.');
        }
        $decimals = $data['decimals'] ?? 2;
        if (! is_int($decimals) || $decimals < 0 || $decimals > SummaryFormula::MAX_DECIMALS) {
            throw InvalidReportDefinition::malformed('"formulas.decimals" must be from 0 to '.SummaryFormula::MAX_DECIMALS.'.');
        }
        $depth = static function (mixed $value, string $what): ?int {
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw InvalidReportDefinition::malformed("\"{$what}\" must be a grouping depth or null.");
            }

            return $value;
        };

        return new SummaryFormula(
            mb_substr($label, 0, 100),
            $expression,
            FormulaType::tryFrom(self::string($data['type'] ?? FormulaType::NUMBER->value, 'formulas.type'))
                ?? throw InvalidReportDefinition::malformed('a formula type must be number, currency or percent.'),
            $decimals,
            $depth($data['down'] ?? null, 'formulas.down'),
            $depth($data['across'] ?? null, 'formulas.across'),
        );
    }

    private static function highlight(mixed $data): Highlight
    {
        if (! is_array($data)) {
            throw InvalidReportDefinition::malformed('each highlight must be an object.');
        }

        $number = static function (mixed $value, string $what): int|float {
            if (is_string($value) && is_numeric($value)) {
                $value += 0;
            }
            if (! is_int($value) && ! is_float($value)) {
                throw InvalidReportDefinition::malformed("\"{$what}\" must be a number.");
            }

            return $value;
        };
        $colors = $data['colors'] ?? ['danger', 'warning', 'success'];
        if (! is_array($colors) || count($colors) !== 3 || ! array_is_list($colors)) {
            throw InvalidReportDefinition::malformed('"highlights.colors" must list three colours (or null for none).');
        }
        foreach ($colors as $color) {
            if ($color !== null && ! in_array($color, Highlight::COLORS, true)) {
                throw InvalidReportDefinition::malformed('a highlight colour must be one of '.implode(', ', Highlight::COLORS).'.');
            }
        }

        return new Highlight(
            self::string($data['target'] ?? null, 'highlights.target'),
            $number($data['low'] ?? null, 'highlights.low'),
            $number($data['high'] ?? null, 'highlights.high'),
            $colors[0],
            $colors[1],
            $colors[2],
        );
    }

    private static function rowFormula(mixed $data): ?RowFormula
    {
        if ($data === null) {
            return null;
        }
        if (! is_array($data)) {
            throw InvalidReportDefinition::malformed('"row_formula" must be an object.');
        }

        $expression = $data['expression'] ?? null;
        if (! is_string($expression) || trim($expression) === '' || strlen($expression) > RowFormula::MAX_LENGTH) {
            throw InvalidReportDefinition::malformed('the row formula needs an expression of at most '.RowFormula::MAX_LENGTH.' characters.');
        }
        $decimals = $data['decimals'] ?? 2;
        if (! is_int($decimals) || $decimals < 0 || $decimals > SummaryFormula::MAX_DECIMALS) {
            throw InvalidReportDefinition::malformed('"row_formula.decimals" must be from 0 to '.SummaryFormula::MAX_DECIMALS.'.');
        }

        return new RowFormula(
            mb_substr(self::string($data['label'] ?? null, 'row_formula.label'), 0, 100),
            $expression,
            FormulaType::tryFrom(self::string($data['type'] ?? FormulaType::NUMBER->value, 'row_formula.type'))
                ?? throw InvalidReportDefinition::malformed('the row formula type must be number, currency or percent.'),
            $decimals,
        );
    }

    private static function chart(mixed $data): ?ChartSpec
    {
        if ($data === null) {
            return null;
        }
        if (! is_array($data)) {
            throw InvalidReportDefinition::malformed('"chart" must be an object.');
        }

        $type = ChartType::tryFrom(self::string($data['type'] ?? null, 'chart.type'))
            ?? throw InvalidReportDefinition::malformed('unknown chart type.');
        $target = $data['target'] ?? null;
        if (is_string($target) && is_numeric($target)) {
            $target += 0;
        }
        if ($target !== null && ! is_int($target) && ! is_float($target)) {
            throw InvalidReportDefinition::malformed('"chart.target" must be a number.');
        }
        $ref = static fn (string $key): ?string => isset($data[$key]) ? self::string($data[$key], 'chart.'.$key) : null;

        return new ChartSpec(
            $type,
            isset($data['value']) ? self::aggregateRef($data['value'], 'chart.value') : self::RECORD_COUNT,
            $ref('x'),
            $ref('series'),
            $target,
        );
    }

    private static function groupSort(mixed $data): ?GroupSort
    {
        if ($data === null) {
            return null;
        }
        if (! is_array($data) || ! is_int($data['depth'] ?? null)) {
            throw InvalidReportDefinition::malformed('"sort_aggregate" needs a depth and an aggregate.');
        }

        return new GroupSort($data['depth'], self::aggregateRef($data['aggregate'] ?? null, 'sort_aggregate.aggregate'), self::direction($data['dir'] ?? 'desc'));
    }

    private static function topN(mixed $data): ?TopN
    {
        if ($data === null) {
            return null;
        }
        if (! is_array($data) || ! is_int($data['limit'] ?? null)) {
            throw InvalidReportDefinition::malformed('"top_n" needs a limit and an aggregate.');
        }

        return new TopN($data['limit'], self::aggregateRef($data['aggregate'] ?? null, 'top_n.aggregate'), self::direction($data['dir'] ?? 'desc'));
    }

    /** `record_count` or `a{i}`; DefinitionValidator checks that `a{i}` exists. */
    private static function aggregateRef(mixed $value, string $what): string
    {
        $ref = self::string($value, $what);

        if ($ref !== self::RECORD_COUNT && preg_match('/^a\d{1,2}$/', $ref) !== 1) {
            throw InvalidReportDefinition::malformed("\"{$what}\" must be record_count or an aggregate (a0, a1, …).");
        }

        return $ref;
    }

    /**
     * @return 'asc'|'desc'
     */
    private static function direction(mixed $value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function bool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        if (! is_bool($value)) {
            throw InvalidReportDefinition::malformed("\"{$key}\" must be true or false.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidReportDefinition::malformed("\"{$key}\" must be a list.");
        }

        return $value;
    }

    private static function string(mixed $value, string $what): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 500) {
            throw InvalidReportDefinition::malformed("\"{$what}\" must be a non-empty string.");
        }

        return $value;
    }
}
