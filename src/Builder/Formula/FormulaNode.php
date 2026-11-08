<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Formula;

/**
 * One node of a parsed summary formula. `ref` is `a{i}` or `record_count`;
 * `call` holds the function name and its arguments; PARENTGROUPVAL and
 * PREVGROUPVAL carry their grouping (`g{d}`, `x{d}`, `grand`) and offset.
 */
final readonly class FormulaNode
{
    public const string NUMBER = 'number';

    public const string REF = 'ref';

    public const string NEGATE = 'negate';

    public const string BINARY = 'binary';

    public const string CALL = 'call';

    /**
     * @param  list<FormulaNode>  $children
     */
    public function __construct(
        public string $kind,
        public int|float|string|null $value = null,
        public array $children = [],
        public ?string $grouping = null,
        public int $offset = 1,
    ) {}
}
