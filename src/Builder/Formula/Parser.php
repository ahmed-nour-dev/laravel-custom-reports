<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Formula;

use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * Parses a summary formula (SPEC §14.6) into a tree, checking every
 * reference against the report: aggregates `a{i}` it has, row groupings
 * `g{d}` and column groupings `x{d}` it has. Any input that isn't a valid
 * formula fails with InvalidReportDefinition, never another error.
 *
 *   expr       := comparison
 *   comparison := additive (( = | <> | < | <= | > | >= ) additive)?
 *   additive   := term (( + | - ) term)*
 *   term       := unary (( * | / ) unary)*
 *   unary      := - unary | primary
 *   primary    := NUMBER | a{i} | RECORD_COUNT | '(' expr ')'
 *               | IF(expr, expr, expr) | ROUND(expr[, expr]) | ABS(expr)
 *               | MIN(expr, …) | MAX(expr, …)
 *               | PARENTGROUPVAL(value, g{d} | x{d} | GRAND_SUMMARY
 *                                       | ROW_GRAND_SUMMARY | COLUMN_GRAND_SUMMARY)
 *               | PREVGROUPVAL(value, g{d} | x{d}[, n])
 *   value      := a{i} | RECORD_COUNT
 */
final class Parser
{
    private const int MAX_NESTING = 30;

    private const int MAX_PREVIOUS = 12;

    /** name => [min args, max args] */
    private const array FUNCTIONS = ['IF' => [3, 3], 'ROUND' => [1, 2], 'ABS' => [1, 1], 'MIN' => [1, 20], 'MAX' => [1, 20]];

    /** @var list<array{string, string}> */
    private array $tokens = [];

    private int $position = 0;

    private int $nesting = 0;

    private int $index = 0;

    private int $aggregates = 0;

    private int $rows = 0;

    private int $columns = 0;

    public function __construct(private readonly Tokenizer $tokenizer = new Tokenizer) {}

    /**
     * @param  int  $index  the formula's position, for error messages
     */
    public function parse(string $formula, int $index, int $aggregates, int $rows, int $columns): FormulaNode
    {
        if (strlen($formula) > SummaryFormula::MAX_LENGTH) {
            throw InvalidReportDefinition::badFormula($index, 'it is too long.');
        }

        $this->index = $index;
        $this->aggregates = $aggregates;
        $this->rows = $rows;
        $this->columns = $columns;
        $this->tokens = $this->tokenizer->tokenize($formula, $index);
        $this->position = 0;
        $this->nesting = 0;

        if ($this->tokens === []) {
            throw $this->bad('it is empty.');
        }

        $node = $this->expression();

        if ($this->position < count($this->tokens)) {
            throw $this->bad("unexpected \"{$this->tokens[$this->position][1]}\".");
        }

        return $node;
    }

    private function expression(): FormulaNode
    {
        if (++$this->nesting > self::MAX_NESTING) {
            throw $this->bad('it nests too deeply.');
        }

        $left = $this->additive();

        if ($this->peek('op') !== null && in_array($this->peek('op'), ['=', '<>', '<', '<=', '>', '>='], true)) {
            $operator = $this->take();
            $left = new FormulaNode(FormulaNode::BINARY, $operator, [$left, $this->additive()]);
        }

        $this->nesting--;

        return $left;
    }

    private function additive(): FormulaNode
    {
        $left = $this->term();

        while (in_array($this->peek('op'), ['+', '-'], true)) {
            $operator = $this->take();
            $left = new FormulaNode(FormulaNode::BINARY, $operator, [$left, $this->term()]);
        }

        return $left;
    }

    private function term(): FormulaNode
    {
        $left = $this->unary();

        while (in_array($this->peek('op'), ['*', '/'], true)) {
            $operator = $this->take();
            $left = new FormulaNode(FormulaNode::BINARY, $operator, [$left, $this->unary()]);
        }

        return $left;
    }

    private function unary(): FormulaNode
    {
        if ($this->peek('op') === '-') {
            $this->next();

            if (++$this->nesting > self::MAX_NESTING) {
                throw $this->bad('it nests too deeply.');
            }
            $node = new FormulaNode(FormulaNode::NEGATE, null, [$this->unary()]);
            $this->nesting--;

            return $node;
        }

        return $this->primary();
    }

    private function primary(): FormulaNode
    {
        $token = $this->next() ?? throw $this->bad('it ends too early.');

        if ($token[0] === 'number') {
            return new FormulaNode(FormulaNode::NUMBER, str_contains($token[1], '.') ? (float) $token[1] : (int) $token[1]);
        }

        if ($token[0] === '(') {
            $node = $this->expression();
            $this->expect(')');

            return $node;
        }

        if ($token[0] !== 'name') {
            throw $this->bad("unexpected \"{$token[1]}\".");
        }

        $name = $token[1];

        if ($this->peek('(') === null) {
            return $this->value($name);
        }

        return match ($name) {
            'PARENTGROUPVAL' => $this->groupValue('parent'),
            'PREVGROUPVAL' => $this->groupValue('previous'),
            default => $this->call($name),
        };
    }

    private function call(string $name): FormulaNode
    {
        [$min, $max] = self::FUNCTIONS[$name] ?? throw $this->bad("there is no function {$name}.");
        $this->expect('(');

        $arguments = [$this->expression()];
        while ($this->peek(',') !== null) {
            $this->next();
            $arguments[] = $this->expression();
        }
        $this->expect(')');

        if (count($arguments) < $min || count($arguments) > $max) {
            throw $this->bad("{$name} takes ".($min === $max ? $min : "{$min} to {$max}").' arguments.');
        }

        return new FormulaNode(FormulaNode::CALL, $name, $arguments);
    }

    /** PARENTGROUPVAL(value, grouping) / PREVGROUPVAL(value, grouping[, n]). */
    private function groupValue(string $which): FormulaNode
    {
        $function = $which === 'parent' ? 'PARENTGROUPVAL' : 'PREVGROUPVAL';
        $this->expect('(');

        $value = $this->value(($this->next() ?? throw $this->bad("{$function} needs a total."))[1]);
        $this->expect(',');
        $grouping = $this->grouping(($this->next() ?? throw $this->bad("{$function} needs a grouping."))[1], $which === 'parent');

        $offset = 1;
        if ($which === 'previous' && $this->peek(',') !== null) {
            $this->next();
            $number = $this->next() ?? throw $this->bad('PREVGROUPVAL is missing how many groups to count back.');
            if ($number[0] !== 'number' || ! ctype_digit($number[1]) || (int) $number[1] < 1 || (int) $number[1] > self::MAX_PREVIOUS) {
                throw $this->bad('PREVGROUPVAL counts back 1 to '.self::MAX_PREVIOUS.' groups.');
            }
            $offset = (int) $number[1];
        }
        $this->expect(')');

        return new FormulaNode(FormulaNode::CALL, $function, [$value], $grouping, $offset);
    }

    private function value(string $name): FormulaNode
    {
        if ($name === 'RECORD_COUNT') {
            return new FormulaNode(FormulaNode::REF, 'record_count');
        }

        if (preg_match('/^A(\d{1,2})$/', $name, $match) === 1) {
            if ((int) $match[1] >= $this->aggregates) {
                throw $this->bad("the report has no aggregate a{$match[1]}.");
            }

            return new FormulaNode(FormulaNode::REF, 'a'.(int) $match[1]);
        }

        throw $this->bad("\"{$name}\" isn't a total (use a0, a1, … or RECORD_COUNT).");
    }

    /**
     * `g{d}` (a row grouping) or `x{d}` (a column grouping); for PARENTGROUPVAL
     * also GRAND_SUMMARY (the grand total), ROW_GRAND_SUMMARY (the column's
     * total down all rows) and COLUMN_GRAND_SUMMARY (the row's total across
     * all columns).
     */
    private function grouping(string $name, bool $allowGrand): string
    {
        $grand = ['GRAND_SUMMARY' => 'grand', 'ROW_GRAND_SUMMARY' => 'rows', 'COLUMN_GRAND_SUMMARY' => 'columns'];
        if ($allowGrand && isset($grand[$name])) {
            return $grand[$name];
        }

        if (preg_match('/^([GX])(\d)$/', $name, $match) === 1) {
            $depth = (int) $match[2];
            $count = $match[1] === 'G' ? $this->rows : $this->columns;

            if ($depth < $count) {
                return strtolower($match[1]).$depth;
            }
        }

        throw $this->bad("\"{$name}\" isn't one of the report's groupings (g0, g1, … for rows, x0, x1 for columns".($allowGrand ? ', or GRAND_SUMMARY, ROW_GRAND_SUMMARY, COLUMN_GRAND_SUMMARY' : '').').');
    }

    private function expect(string $type): void
    {
        $token = $this->next();

        if ($token === null || $token[0] !== $type) {
            throw $this->bad($token === null ? "a \"{$type}\" is missing." : "expected \"{$type}\", found \"{$token[1]}\".");
        }
    }

    private function peek(string $type): ?string
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token[0] === $type ? $token[1] : null;
    }

    /**
     * @return array{string, string}|null
     *
     * @phpstan-impure
     */
    private function next(): ?array
    {
        $token = $this->tokens[$this->position] ?? null;
        $this->position++;

        return $token;
    }

    /** The next token's text, after peek() saw it. */
    private function take(): string
    {
        return ($this->next() ?? throw $this->bad('it ends too early.'))[1];
    }

    private function bad(string $reason): InvalidReportDefinition
    {
        return InvalidReportDefinition::badFormula($this->index, $reason);
    }
}
