<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Builder\Definition\FilterLogicParser;
use AhmedNour\CustomReports\Builder\Definition\LogicNode;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * SPEC §7.1: only numbers, AND, OR, NOT and parentheses; NOT binds tighter
 * than AND, AND tighter than OR; every defined filter must be used.
 */
final class FilterLogicParserTest extends TestCase
{
    public function test_and_binds_tighter_than_or(): void
    {
        $this->assertSame('or(1, and(2, 3))', $this->show((new FilterLogicParser)->parse('1 OR 2 AND 3', [1, 2, 3])));
        $this->assertSame('and(or(1, 2), 3)', $this->show((new FilterLogicParser)->parse('(1 OR 2) AND 3', [1, 2, 3])));
    }

    public function test_not_binds_tightest_and_keywords_are_case_insensitive(): void
    {
        $this->assertSame('and(1, or(2, not(3)))', $this->show((new FilterLogicParser)->parse('1 and (2 or not 3)', [1, 2, 3])));
        $this->assertSame('not(and(1, 2))', $this->show((new FilterLogicParser)->parse('NOT (1 AND 2)', [1, 2])));
    }

    public function test_the_default_is_every_filter_anded(): void
    {
        $this->assertSame('and(1, 2, 3)', $this->show(FilterLogicParser::allOf([1, 2, 3])));
        $this->assertNull(FilterLogicParser::allOf([]));
    }

    public function test_it_knows_when_a_filter_sits_under_or_or_not(): void
    {
        $logic = (new FilterLogicParser)->parse('1 AND (2 OR 3) AND NOT 4', [1, 2, 3, 4]);

        $this->assertFalse($logic->isUnderOrOrNot(1));
        $this->assertTrue($logic->isUnderOrOrNot(2));
        $this->assertTrue($logic->isUnderOrOrNot(4));
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function invalid(): array
    {
        return [
            'empty' => ['   ', [1]],
            'unknown filter' => ['1 AND 4', [1]],
            'unused filter' => ['1', [1, 2]],
            'dangling operator' => ['1 AND', [1]],
            'two numbers' => ['1 2', [1, 2]],
            'unclosed' => ['(1 AND 2', [1, 2]],
            'stray close' => ['1 AND 2)', [1, 2]],
            'sql' => ['1; DROP TABLE users', [1]],
            'word' => ['1 XOR 2', [1, 2]],
            'deep' => [str_repeat('(', 30).'1'.str_repeat(')', 30), [1]],
            'too long' => [implode(' AND ', array_fill(0, 200, '1')), [1]],
        ];
    }

    /**
     * @param  list<int>  $defined
     */
    #[DataProvider('invalid')]
    public function test_bad_logic_is_refused(string $logic, array $defined): void
    {
        $this->expectException(InvalidReportDefinition::class);

        (new FilterLogicParser)->parse($logic, $defined);
    }

    /** Random input never throws anything but InvalidReportDefinition. */
    public function test_fuzzed_input_only_ever_fails_cleanly(): void
    {
        mt_srand(20261104);
        $alphabet = ['1', '2', '3', ' ', '(', ')', 'AND', 'OR', 'NOT', 'and', '"', "'", ';', '-', '%', "\0", 'é', '9999'];

        for ($i = 0; $i < 2000; $i++) {
            $logic = '';
            for ($j = 0, $n = mt_rand(0, 25); $j < $n; $j++) {
                $logic .= $alphabet[array_rand($alphabet)].(mt_rand(0, 1) === 1 ? ' ' : '');
            }

            try {
                (new FilterLogicParser)->parse($logic, [1, 2, 3]);
                $this->addToAssertionCount(1);
            } catch (InvalidReportDefinition) {
                $this->addToAssertionCount(1);
            } catch (Throwable $e) {
                $this->fail('Logic '.json_encode($logic).' threw '.$e::class.': '.$e->getMessage());
            }
        }
    }

    private function show(?LogicNode $node): string
    {
        if (! $node instanceof LogicNode) {
            return '';
        }

        return $node->kind === LogicNode::REF
            ? (string) $node->number
            : $node->kind.'('.implode(', ', array_map($this->show(...), $node->children)).')';
    }
}
