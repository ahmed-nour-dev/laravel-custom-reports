<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Formula;

use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * Parses the row formula (SPEC §14.6, 9b) into a tree whose every node has a
 * fixed SQL template (RowFormulaCompiler). Operands are numbers and field
 * keys `l{N}.field`; DefinitionValidator then checks the fields are in the
 * layout, on one level, and of a type that fits. Any input that isn't a valid
 * row formula fails with InvalidReportDefinition, never another error.
 *
 *   expr       := comparison
 *   comparison := additive (( = | <> | < | <= | > | >= ) additive)?
 *   additive   := term (( + | - ) term)*
 *   term       := unary (( * | / ) unary)*
 *   unary      := - unary | primary
 *   primary    := NUMBER | l{N}.field | '(' expr ')'
 *               | IF(expr, expr, expr) | COALESCE(expr, …) | ROUND(expr[, 0–10])
 *               | ABS(expr) | DATEDIFF(date field, date field)
 */
final class RowParser
{
    private const int MAX_NESTING = 30;

    /** name => [min args, max args] */
    private const array FUNCTIONS = ['IF' => [3, 3], 'COALESCE' => [1, 10], 'ROUND' => [1, 2], 'ABS' => [1, 1], 'DATEDIFF' => [2, 2]];

    /** @var list<array{string, string}> */
    private array $tokens = [];

    private int $position = 0;

    private int $nesting = 0;

    public function __construct(private readonly Tokenizer $tokenizer = new Tokenizer) {}

    public function parse(string $formula): FormulaNode
    {
        if (strlen($formula) > RowFormula::MAX_LENGTH) {
            throw InvalidReportDefinition::badRowFormula('it is too long.');
        }

        try {
            $this->tokens = $this->tokenizer->tokenize($formula, 0);
        } catch (InvalidReportDefinition $e) {
            throw InvalidReportDefinition::badRowFormula((string) ($e->context()['reason'] ?? 'it can\'t be read.'));
        }
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

    /**
     * Every field key the tree reads.
     *
     * @return list<string>
     */
    public static function fields(FormulaNode $node): array
    {
        if ($node->kind === FormulaNode::REF) {
            return [(string) $node->value];
        }

        return array_values(array_unique(array_merge([], ...array_map(self::fields(...), $node->children))));
    }

    private function expression(): FormulaNode
    {
        if (++$this->nesting > self::MAX_NESTING) {
            throw $this->bad('it nests too deeply.');
        }

        $left = $this->additive();

        if (in_array($this->peek('op'), ['=', '<>', '<', '<=', '>', '>='], true)) {
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
        if ($this->peek('op') !== '-') {
            return $this->primary();
        }

        $this->next();
        if (++$this->nesting > self::MAX_NESTING) {
            throw $this->bad('it nests too deeply.');
        }
        $node = new FormulaNode(FormulaNode::NEGATE, null, [$this->unary()]);
        $this->nesting--;

        return $node;
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

        if ($this->peek('(') !== null) {
            return $this->call($token[1]);
        }

        $key = strtolower($token[1]);
        if (preg_match('/^l[0-3]\.[a-z_]+$/', $key) !== 1) {
            throw $this->bad("\"{$token[1]}\" isn't a field of this report.");
        }

        return new FormulaNode(FormulaNode::REF, $key);
    }

    private function call(string $name): FormulaNode
    {
        [$min, $max] = self::FUNCTIONS[$name] ?? throw $this->bad("there is no function {$name} in a row formula.");
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

        if ($name === 'ROUND' && isset($arguments[1])
            && ($arguments[1]->kind !== FormulaNode::NUMBER || ! is_int($arguments[1]->value) || $arguments[1]->value > 10)) {
            throw $this->bad('ROUND rounds to 0 to 10 decimal places, written as a whole number.');
        }

        if ($name === 'DATEDIFF' && ($arguments[0]->kind !== FormulaNode::REF || $arguments[1]->kind !== FormulaNode::REF)) {
            throw $this->bad('DATEDIFF takes two date fields.');
        }

        return new FormulaNode(FormulaNode::CALL, $name, $arguments);
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

    private function take(): string
    {
        return ($this->next() ?? throw $this->bad('it ends too early.'))[1];
    }

    private function bad(string $reason): InvalidReportDefinition
    {
        return InvalidReportDefinition::badRowFormula($reason);
    }
}
