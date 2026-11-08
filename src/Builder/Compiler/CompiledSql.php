<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * SQL the compiler built from its own fixed templates (RowFormulaCompiler),
 * with grammar-wrapped columns and `?` placeholders; the caller adds the
 * bindings next to it. Never wraps request input.
 */
final readonly class CompiledSql implements Expression
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
