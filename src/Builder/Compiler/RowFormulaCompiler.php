<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\Formula\FormulaNode;
use AhmedNour\CustomReports\Enums\FormulaType;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * The row formula as SQL (SPEC §14.6, 9b). Every node has a fixed template
 * below; columns are wrapped by the query grammar and every number is a
 * bound parameter, so nothing typed in the formula reaches SQL as text.
 * Division is `a / nullif(b, 0)`: dividing by zero gives null, not an error.
 */
final class RowFormulaCompiler
{
    private const array OPERATORS = ['+' => '+', '-' => '-', '*' => '*', '=' => '=', '<>' => '<>', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>='];

    /**
     * $decimals: the places a value keeps (a percent, shown ×100, two more).
     *
     * @return array{sql: string, bindings: list<int|float>, level: int, decimals: int}|null
     */
    public function compile(CompiledQuery $compiled, ValidatedDefinition $validated): ?array
    {
        $row = $validated->rowFormula;

        if ($row === null) {
            return null;
        }

        [$alias] = $compiled->joins->resolve($row['level'], null);
        $bindings = [];
        $sql = $this->render($row['node'], $validated, $alias, $compiled->query->getGrammar(), $bindings);

        $formula = $row['formula'];

        return [
            'sql' => $sql,
            'bindings' => $bindings,
            'level' => $row['level'],
            'decimals' => $formula->decimals + ($formula->type === FormulaType::PERCENT ? 2 : 0),
        ];
    }

    /**
     * @param  list<int|float>  $bindings
     */
    private function render(FormulaNode $node, ValidatedDefinition $validated, string $alias, Grammar $grammar, array &$bindings): string
    {
        // By reference: an arrow function would copy $bindings, and nested numbers would be lost.
        $child = function (int $i) use ($node, $validated, $alias, $grammar, &$bindings): string {
            return $this->render($node->children[$i], $validated, $alias, $grammar, $bindings);
        };

        switch ($node->kind) {
            case FormulaNode::NUMBER:
                $bindings[] = is_int($node->value) ? $node->value : (float) $node->value;

                return '?';

            case FormulaNode::REF:
                return $grammar->wrap($alias.'.'.$validated->field((string) $node->value)->field->name);

            case FormulaNode::NEGATE:
                return '(-'.$child(0).')';

            case FormulaNode::BINARY:
                $left = $child(0);
                $right = $child(1);

                if ($node->value === '/') {
                    return '('.$left.' / nullif('.$right.', 0))';
                }

                $operator = self::OPERATORS[(string) $node->value] ?? throw new InvalidArgumentException('Unknown operator.');

                return '('.$left.' '.$operator.' '.$right.')';
        }

        return match ($node->value) {
            'IF' => '(case when '.$child(0).' then '.$child(1).' else '.$child(2).' end)',
            'COALESCE' => 'coalesce('.implode(', ', array_map($child, array_keys($node->children))).')',
            'ROUND' => (function () use ($node, $child, &$bindings): string {
                $value = $child(0);
                $bindings[] = isset($node->children[1]) ? (int) $node->children[1]->value : 0;

                return 'round('.$value.', ?)';
            })(),
            'ABS' => 'abs('.$child(0).')',
            'DATEDIFF' => 'datediff('.$child(0).', '.$child(1).')',
            default => throw new InvalidArgumentException('Unknown function.'),
        };
    }
}
