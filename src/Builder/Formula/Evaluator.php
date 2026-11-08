<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Formula;

use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Enums\FormulaType;

/**
 * Works out summary formulas over a fact map (SPEC §14.6), in PHP after the
 * SQL ran. Each formula lands in `cell.formulas.f{i}` of every cell where it
 * shows (its `down` / `across` level, or everywhere).
 *
 * Blank in, blank out: a missing or non-numeric total, or a division by
 * zero, gives null, and null spreads through arithmetic. IF treats null
 * and 0 as false; MIN and MAX skip blanks. PARENTGROUPVAL reads the same
 * total from the cell's ancestor at a grouping, or a grand total (overall,
 * down all rows, or across all columns);
 * PREVGROUPVAL from the group n places before at that grouping, under the
 * same parent — null when there is none.
 */
final class Evaluator
{
    /** @var array<string, array<string, mixed>> */
    private array $cells = [];

    /**
     * @param  list<array{SummaryFormula, FormulaNode}>  $formulas
     * @param  array<string, array<string, mixed>>  $cells  fact_map
     * @return array<string, array<string, mixed>>
     */
    public function apply(array $formulas, array $cells): array
    {
        if ($formulas === []) {
            return $cells;
        }

        $this->cells = $cells;

        foreach (array_keys($cells) as $key) {
            [$row, $column] = explode('!', (string) $key, 2);

            foreach ($formulas as $index => [$formula, $node]) {
                if (! $this->shows($formula->down, $row) || ! $this->shows($formula->across, $column)) {
                    continue;
                }

                $cells[$key]['formulas']['f'.$index] = $this->rounded($this->evaluate($node, $row, $column), $formula);
            }
        }

        return $cells;
    }

    /**
     * Rounded to the places shown: a percent is a fraction shown ×100, so it
     * keeps two more (0.756 with 0 places → 0.76 → 76%). No "-0".
     */
    private function rounded(?float $value, SummaryFormula $formula): ?float
    {
        if ($value === null || is_nan($value) || is_infinite($value)) {
            return null;
        }

        $rounded = round($value, $formula->decimals + ($formula->type === FormulaType::PERCENT ? 2 : 0));

        // abs() turns -0.0 into 0.0 (a plain `== 0.0 ? 0.0` reads as a no-op to Rector, and it removes it).
        return $rounded === 0.0 ? abs($rounded) : $rounded;
    }

    /** null: every level, the totals too; otherwise only groups at that depth. */
    private function shows(?int $depth, string $path): bool
    {
        return $depth === null || ($path !== FactMap::TOTAL && substr_count($path, '_') === $depth);
    }

    private function evaluate(FormulaNode $node, string $row, string $column): ?float
    {
        switch ($node->kind) {
            case FormulaNode::NUMBER:
                return (float) $node->value;

            case FormulaNode::REF:
                return $this->total((string) $node->value, $row, $column);

            case FormulaNode::NEGATE:
                $value = $this->evaluate($node->children[0], $row, $column);

                return $value === null ? null : -$value;

            case FormulaNode::BINARY:
                $left = $this->evaluate($node->children[0], $row, $column);
                $right = $this->evaluate($node->children[1], $row, $column);

                if ($left === null || $right === null) {
                    return null;
                }

                return match ($node->value) {
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $right == 0 ? null : $left / $right,
                    '=' => (float) ($left === $right),
                    '<>' => (float) ($left !== $right),
                    '<' => (float) ($left < $right),
                    '<=' => (float) ($left <= $right),
                    '>' => (float) ($left > $right),
                    '>=' => (float) ($left >= $right),
                    default => null,
                };

            default:
                return $this->call($node, $row, $column);
        }
    }

    private function call(FormulaNode $node, string $row, string $column): ?float
    {
        $argument = fn (int $i): ?float => $this->evaluate($node->children[$i], $row, $column);

        switch ($node->value) {
            case 'IF':
                $condition = $argument(0);

                return $condition !== null && $condition != 0 ? $argument(1) : $argument(2);

            case 'ROUND':
                $value = $argument(0);
                $places = count($node->children) > 1 ? $argument(1) : 0.0;

                return $value === null || $places === null ? null : round($value, max(0, min(10, (int) $places)));

            case 'ABS':
                $value = $argument(0);

                return $value === null ? null : abs($value);

            case 'MIN':
            case 'MAX':
                $values = array_values(array_filter(
                    array_map(fn (FormulaNode $child): ?float => $this->evaluate($child, $row, $column), $node->children),
                    static fn (?float $value): bool => $value !== null,
                ));

                return $values === [] ? null : (float) ($node->value === 'MIN' ? min($values) : max($values));

            case 'PARENTGROUPVAL':
                [$row, $column] = $this->parent((string) $node->grouping, $row, $column) ?? [null, null];

                return $row === null || $column === null ? null : $this->total((string) $node->children[0]->value, $row, $column);

            case 'PREVGROUPVAL':
                [$row, $column] = $this->previous((string) $node->grouping, $node->offset, $row, $column) ?? [null, null];

                return $row === null || $column === null ? null : $this->total((string) $node->children[0]->value, $row, $column);
        }

        return null;
    }

    /**
     * The ancestor cell at a grouping: the path cut after depth d on that axis.
     *
     * @return array{string, string}|null
     */
    private function parent(string $grouping, string $row, string $column): ?array
    {
        if ($grouping === 'grand') {
            return [FactMap::TOTAL, FactMap::TOTAL];
        }
        if ($grouping === 'rows') {
            return [FactMap::TOTAL, $column];
        }
        if ($grouping === 'columns') {
            return [$row, FactMap::TOTAL];
        }

        $depth = (int) substr($grouping, 1);
        $isRow = $grouping[0] === 'g';
        $path = $isRow ? $row : $column;

        if ($path === FactMap::TOTAL || substr_count($path, '_') < $depth) {
            return null;
        }

        $cut = implode('_', array_slice(explode('_', $path), 0, $depth + 1));

        return $isRow ? [$cut, $column] : [$row, $cut];
    }

    /**
     * The group `offset` places before at a grouping, deeper positions kept.
     *
     * @return array{string, string}|null
     */
    private function previous(string $grouping, int $offset, string $row, string $column): ?array
    {
        $depth = (int) substr($grouping, 1);
        $isRow = $grouping[0] === 'g';
        $path = $isRow ? $row : $column;

        if ($path === FactMap::TOTAL || substr_count($path, '_') < $depth) {
            return null;
        }

        $segments = explode('_', $path);
        $segments[$depth] = (string) ((int) $segments[$depth] - $offset);

        if ((int) $segments[$depth] < 0) {
            return null;
        }

        $moved = implode('_', $segments);

        return $isRow ? [$moved, $column] : [$row, $moved];
    }

    private function total(string $ref, string $row, string $column): ?float
    {
        $cell = $this->cells[$row.'!'.$column] ?? null;

        if ($cell === null) {
            return null;
        }

        $value = $ref === 'record_count' ? ($cell['record_count'] ?? null) : ($cell['aggregates'][$ref] ?? null);

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
