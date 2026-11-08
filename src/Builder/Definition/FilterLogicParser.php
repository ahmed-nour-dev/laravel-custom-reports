<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * Parses filter logic like `1 AND (2 OR NOT 3)` (SPEC §7.1). Only numbers,
 * AND, OR, NOT and parentheses are accepted; anything else — and any input
 * at all — fails with InvalidReportDefinition, never another error.
 *
 *   expr   := term (OR term)*
 *   term   := factor (AND factor)*
 *   factor := NOT factor | '(' expr ')' | NUMBER
 *
 * The result is a tree; the logic string never reaches SQL as text.
 */
final class FilterLogicParser
{
    public const int MAX_LENGTH = 500;

    private const int MAX_NESTING = 20;

    /** @var list<array{string, string}> [type, text] */
    private array $tokens = [];

    private int $position = 0;

    private int $nesting = 0;

    /**
     * @param  list<int>  $defined  the filter numbers that exist; each must appear in the logic
     */
    public function parse(string $logic, array $defined): LogicNode
    {
        if (strlen($logic) > self::MAX_LENGTH) {
            throw InvalidReportDefinition::badLogic('it is too long.');
        }

        $this->tokens = $this->tokenize($logic);
        $this->position = 0;
        $this->nesting = 0;

        if ($this->tokens === []) {
            throw InvalidReportDefinition::badLogic('it is empty.');
        }

        $node = $this->expression();

        if ($this->position < count($this->tokens)) {
            throw InvalidReportDefinition::badLogic("unexpected \"{$this->tokens[$this->position][1]}\".");
        }

        $used = array_values(array_unique($node->numbers()));
        sort($used);
        $expected = array_values(array_unique($defined));
        sort($expected);

        if ($unknown = array_diff($used, $expected)) {
            throw InvalidReportDefinition::badLogic('filter '.implode(', ', $unknown).' does not exist.');
        }

        if ($missing = array_diff($expected, $used)) {
            throw InvalidReportDefinition::badLogic('filter '.implode(', ', $missing).' is not used.');
        }

        return $node;
    }

    /**
     * All filters ANDed — the default when no logic is given.
     *
     * @param  list<int>  $numbers
     */
    public static function allOf(array $numbers): ?LogicNode
    {
        return $numbers === [] ? null : LogicNode::all(array_map(LogicNode::ref(...), array_values($numbers)));
    }

    /**
     * @return list<array{string, string}>
     */
    private function tokenize(string $logic): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($logic);

        while ($offset < $length) {
            if (preg_match('/\G\s+/', $logic, $m, 0, $offset)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G(\d{1,3})/', $logic, $m, 0, $offset)) {
                $tokens[] = ['num', $m[1]];
            } elseif (preg_match('/\G(AND|OR|NOT)\b/i', $logic, $m, 0, $offset)) {
                $tokens[] = [strtolower($m[1]), $m[1]];
            } elseif ($logic[$offset] === '(' || $logic[$offset] === ')') {
                $tokens[] = [$logic[$offset], $logic[$offset]];
                $m = [$logic[$offset]];
            } else {
                $snippet = mb_substr(substr($logic, $offset), 0, 10);

                throw InvalidReportDefinition::badLogic("unexpected \"{$snippet}\".");
            }

            $offset += strlen($m[0]);
        }

        return $tokens;
    }

    private function expression(): LogicNode
    {
        $terms = [$this->term()];

        while ($this->peek() === 'or') {
            $this->position++;
            $terms[] = $this->term();
        }

        return LogicNode::any($terms);
    }

    private function term(): LogicNode
    {
        $factors = [$this->factor()];

        while ($this->peek() === 'and') {
            $this->position++;
            $factors[] = $this->factor();
        }

        return LogicNode::all($factors);
    }

    private function factor(): LogicNode
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            throw InvalidReportDefinition::badLogic('it ends too early.');
        }

        if (++$this->nesting > self::MAX_NESTING) {
            throw InvalidReportDefinition::badLogic('it is nested too deeply.');
        }

        try {
            $this->position++;

            return match ($token[0]) {
                'num' => LogicNode::ref((int) $token[1]),
                'not' => LogicNode::not($this->factor()),
                '(' => $this->group(),
                default => throw InvalidReportDefinition::badLogic("unexpected \"{$token[1]}\"."),
            };
        } finally {
            $this->nesting--;
        }
    }

    private function group(): LogicNode
    {
        $node = $this->expression();

        if ($this->peek() !== ')') {
            throw InvalidReportDefinition::badLogic('a "(" is not closed.');
        }

        $this->position++;

        return $node;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position][0] ?? null;
    }
}
