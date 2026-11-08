<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder;

use AhmedNour\CustomReports\Builder\Compiler\Alias;
use AhmedNour\CustomReports\Builder\Compiler\BaseQueryCompiler;
use AhmedNour\CustomReports\Builder\Compiler\SelectCompiler;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * SPEC §11.7, a warning and never a blocker: EXPLAIN the report type's base
 * query with the default columns' lookups, and report every joined table
 * MySQL would read with a full scan (`type = ALL`) and no usable index
 * (`possible_keys` empty) as a missing index on the column it joins on.
 *
 * A full scan alone is not enough: on small tables, or when an inner join
 * lets MySQL drive from the child, it scans on purpose although the index
 * exists.
 */
final readonly class ExplainCheck
{
    public function __construct(
        private BaseQueryCompiler $compiler,
        private SelectCompiler $select,
        private DefaultColumns $defaults,
    ) {}

    /**
     * @return list<array{table: string, column: string, message: string}>
     */
    public function warnings(ReportType $type, Authenticatable $viewer): array
    {
        $compiled = $this->compiler->base($type, $viewer);
        $this->select->select($compiled, $this->defaults->keys($type, $compiled));

        // alias => [table, column the join matches on]
        $joinColumns = [];
        foreach ($compiled->edges as $level => $edge) {
            $joinColumns[Alias::level($level)] = [$edge->child->table(), $edge->foreignKey];
        }
        foreach ($compiled->joins->edges() as $alias => $edge) {
            $joinColumns[$alias] = [$edge->parent->table(), $edge->ownerKey];
        }

        $plan = $compiled->query->getConnection()->select('EXPLAIN '.$compiled->query->toSql(), $compiled->query->getBindings());

        $warnings = [];
        foreach ($plan as $row) {
            $row = (array) $row;
            $alias = is_string($row['table'] ?? null) ? $row['table'] : null;

            if ($alias === null || ! isset($joinColumns[$alias]) || ($row['type'] ?? null) !== 'ALL' || ! empty($row['possible_keys'])) {
                continue;
            }

            [$table, $column] = $joinColumns[$alias];
            $warnings[$table.'.'.$column] = [
                'table' => $table,
                'column' => $column,
                'message' => "Missing index on {$table}.{$column}: every run reads the whole table.",
            ];
        }

        return array_values($warnings);
    }
}
