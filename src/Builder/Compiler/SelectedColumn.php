<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Registry\Field;
use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\RowFormula;

/** A selected report column: its generated SQL alias (`c0`, `c1`…) and what it reads. */
final readonly class SelectedColumn
{
    public function __construct(
        public string $alias,
        public string $tableAlias,
        public FieldRef $ref,
        public Field $field,
        public ReportableSource $source,
        public ?Bucket $bucket = null,
        public bool $isRowFormula = false,
        public int $rowDecimals = 0,
    ) {}

    /** The key the definition used: the field key, `bucket:{key}`, or RowFormula::KEY. */
    public function key(): string
    {
        return $this->isRowFormula ? RowFormula::KEY : ($this->bucket?->columnKey() ?? $this->ref->key);
    }
}
