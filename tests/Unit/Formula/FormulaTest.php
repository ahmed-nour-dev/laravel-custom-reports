<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Formula;

use AhmedNour\CustomReports\Builder\Formula\Evaluator;
use AhmedNour\CustomReports\Builder\Formula\Parser;
use AhmedNour\CustomReports\Data\SummaryFormula;
use AhmedNour\CustomReports\Enums\FormulaType;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Summary formulas (SPEC §14.6): the parser's grammar and refusals, the
 * evaluator over a hand-built fact map, and fuzzing — any input may only
 * ever fail with InvalidReportDefinition.
 *
 * The fact map: two regions (0, 1), region 0 split in two statuses
 * (0_0, 0_1), region 1 in one (1_0). a0 is a sum, a1 an average.
 */
final class FormulaTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private const array CELLS = [
        'T!T' => ['record_count' => 10, 'aggregates' => ['a0' => 200, 'a1' => 20]],
        '0!T' => ['record_count' => 6, 'aggregates' => ['a0' => 150, 'a1' => 25]],
        '0_0!T' => ['record_count' => 4, 'aggregates' => ['a0' => 100, 'a1' => 25]],
        '0_1!T' => ['record_count' => 2, 'aggregates' => ['a0' => 50, 'a1' => null]],
        '1!T' => ['record_count' => 4, 'aggregates' => ['a0' => 50, 'a1' => 12.5]],
        '1_0!T' => ['record_count' => 4, 'aggregates' => ['a0' => 50, 'a1' => 12.5]],
    ];

    /**
     * @return iterable<string, array{string, string, float|null}>
     */
    public static function values(): iterable
    {
        yield 'precedence' => ['1 + 2 * 3', '0!T', 7.0];
        yield 'parentheses' => ['(1 + 2) * 3', '0!T', 9.0];
        yield 'unary minus' => ['-a0 + 10', '0!T', -140.0];
        yield 'refs, any case' => ['A0 / record_count', '0!T', 25.0];
        yield 'division by zero' => ['a0 / (record_count - 6)', '0!T', null];
        yield 'blank spreads' => ['a1 + 1', '0_1!T', null];
        yield 'comparison' => ['a0 > 100', '0!T', 1.0];
        yield 'if' => ['IF(a0 >= 100, 1, 0)', '1!T', 0.0];
        yield 'if on a blank' => ['IF(a1, 1, 2)', '0_1!T', 2.0];
        yield 'round' => ['ROUND(a0 / 3, 1)', '0!T', 50.0];
        yield 'round to whole' => ['ROUND(10 / 4)', '0!T', 3.0];
        yield 'abs' => ['ABS(a1 - a0)', '0!T', 125.0];
        yield 'min skips blanks' => ['MIN(a1, a0, 5)', '0_1!T', 5.0];
        yield 'max' => ['MAX(a1, 3)', '1!T', 12.5];
        yield 'share of the grand total' => ['a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)', '0!T', 0.75];
        yield 'share of the parent' => ['a0 / PARENTGROUPVAL(a0, g0)', '0_1!T', 50 / 150];
        yield 'parent of a parent is itself' => ['PARENTGROUPVAL(RECORD_COUNT, g0)', '0!T', 6.0];
        yield 'no parent at the total' => ['PARENTGROUPVAL(a0, g0)', 'T!T', null];
        yield 'previous group' => ['a0 - PREVGROUPVAL(a0, g0)', '1!T', -100.0];
        yield 'no previous group' => ['PREVGROUPVAL(a0, g0)', '0!T', null];
        yield 'previous keeps the position below' => ['PREVGROUPVAL(a0, g0)', '1_0!T', 100.0];
        yield 'previous under the same parent' => ['PREVGROUPVAL(a0, g1)', '0_1!T', 100.0];
        yield 'two back is nothing' => ['PREVGROUPVAL(a0, g0, 2)', '1!T', null];
    }

    #[DataProvider('values')]
    public function test_values(string $expression, string $cell, ?float $expected): void
    {
        $formula = new SummaryFormula('F', $expression, decimals: 6);
        $node = (new Parser)->parse($expression, 0, 2, 2, 0);
        $formulas = (new Evaluator)->apply([[$formula, $node]], self::CELLS)[$cell]['formulas'] ?? [];
        $this->assertArrayHasKey('f0', $formulas);
        $value = $formulas['f0'];

        $expected === null ? $this->assertNull($value) : $this->assertEqualsWithDelta($expected, $value, 0.000001);
    }

    public function test_a_percent_keeps_two_more_places_and_there_is_no_negative_zero(): void
    {
        $percent = new SummaryFormula('Share', 'a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)', FormulaType::PERCENT, 0);
        $change = new SummaryFormula('Change', '(a0 - 150) / 1000', FormulaType::NUMBER, 0);
        $parser = new Parser;
        $cells = (new Evaluator)->apply([
            [$percent, $parser->parse($percent->expression, 0, 2, 2, 0)],
            [$change, $parser->parse($change->expression, 1, 2, 2, 0)],
        ], self::CELLS);

        $this->assertSame(0.75, $cells['0!T']['formulas']['f0']);        // 75 %, not rounded to 1 (100 %)
        $this->assertSame(0.25, $cells['1_0!T']['formulas']['f0']);
        $this->assertSame(0.0, $cells['1!T']['formulas']['f1']);         // -0.1 rounds to 0…
        $this->assertSame('0.0', json_encode($cells['1!T']['formulas']['f1'], JSON_PRESERVE_ZERO_FRACTION)); // …not -0
    }

    public function test_a_matrix_reads_its_row_and_column_totals(): void
    {
        $cells = [
            'T!T' => ['record_count' => 10, 'aggregates' => []],
            '0!T' => ['record_count' => 6, 'aggregates' => []],
            'T!0' => ['record_count' => 5, 'aggregates' => []],
            '0!0' => ['record_count' => 3, 'aggregates' => []],
        ];
        $value = function (string $expression) use ($cells): mixed {
            $formula = new SummaryFormula('F', $expression, decimals: 4);

            return (new Evaluator)->apply([[$formula, (new Parser)->parse($expression, 0, 0, 1, 1)]], $cells)['0!0']['formulas']['f0'];
        };

        $this->assertEquals(0.5, $value('RECORD_COUNT / PARENTGROUPVAL(RECORD_COUNT, COLUMN_GRAND_SUMMARY)'));
        $this->assertEquals(0.6, $value('RECORD_COUNT / PARENTGROUPVAL(RECORD_COUNT, ROW_GRAND_SUMMARY)'));
        $this->assertEquals(0.3, $value('RECORD_COUNT / PARENTGROUPVAL(RECORD_COUNT, GRAND_SUMMARY)'));
        $this->assertEquals(1.0, $value('RECORD_COUNT / PARENTGROUPVAL(RECORD_COUNT, x0)'));
    }

    public function test_a_formula_shows_where_it_is_placed_and_is_rounded(): void
    {
        $everywhere = new SummaryFormula('Avg', 'a0 / RECORD_COUNT', FormulaType::NUMBER, 1);
        $regions = new SummaryFormula('Share', 'a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)', FormulaType::PERCENT, 2, down: 0);
        $parser = new Parser;
        $cells = (new Evaluator)->apply([
            [$everywhere, $parser->parse($everywhere->expression, 0, 2, 2, 0)],
            [$regions, $parser->parse($regions->expression, 1, 2, 2, 0)],
        ], self::CELLS);

        $this->assertSame(['f0' => 20.0], $cells['T!T']['formulas']);
        $this->assertSame(['f0' => 25.0, 'f1' => 0.75], $cells['0!T']['formulas']);
        $this->assertSame(['f0' => 12.5, 'f1' => 0.25], $cells['1!T']['formulas']);
        $this->assertSame(['f0' => 25.0], $cells['0_1!T']['formulas']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'unknown aggregate' => ['a2 + 1'];
        yield 'not a total' => ['revenue * 2'];
        yield 'unknown function' => ['SQRT(a0)'];
        yield 'too few arguments' => ['IF(a0, 1)'];
        yield 'too many arguments' => ['ABS(a0, 1)'];
        yield 'unknown grouping' => ['PARENTGROUPVAL(a0, g2)'];
        yield 'no column groupings' => ['PARENTGROUPVAL(a0, x0)'];
        yield 'grand total for previous' => ['PREVGROUPVAL(a0, GRAND_SUMMARY)'];
        yield 'counting back zero' => ['PREVGROUPVAL(a0, g0, 0)'];
        yield 'counting back too far' => ['PREVGROUPVAL(a0, g0, 13)'];
        yield 'counting back an expression' => ['PREVGROUPVAL(a0, g0, 1 + 1)'];
        yield 'a formula as the total' => ['PARENTGROUPVAL(a0 + 1, g0)'];
        yield 'unclosed' => ['(a0 + 1'];
        yield 'trailing' => ['a0 a1'];
        yield 'dangling operator' => ['a0 +'];
        yield 'illegal character' => ['a0 ; DROP TABLE x'];
        yield 'string' => ['"a0"'];
        yield 'too long' => [str_repeat('1+', 2000).'1'];
        yield 'too deep' => [str_repeat('(', 40).'1'.str_repeat(')', 40)];
        yield 'too many minuses' => [str_repeat('-', 40).'1'];
    }

    #[DataProvider('refusals')]
    public function test_refusals(string $expression): void
    {
        $this->expectException(InvalidReportDefinition::class);
        (new Parser)->parse($expression, 0, 2, 2, 0);
    }

    public function test_fuzzed_input_only_ever_fails_cleanly(): void
    {
        $alphabet = ['a0', 'a1', 'a9', 'RECORD_COUNT', 'IF', 'ROUND', 'ABS', 'MIN', 'MAX', 'PARENTGROUPVAL', 'PREVGROUPVAL',
            'g0', 'g1', 'x0', 'GRAND_SUMMARY', '(', ')', ',', '+', '-', '*', '/', '<', '<=', '>', '>=', '=', '<>', '!=',
            '0', '1', '2.5', '13', '.', ';', "'", '"', '%', '`', 'é', '?', ' '];
        $parser = new Parser;
        $evaluator = new Evaluator;

        for ($i = 0; $i < 2000; $i++) {
            $formula = '';
            for ($j = 0, $n = mt_rand(0, 25); $j < $n; $j++) {
                $formula .= $alphabet[array_rand($alphabet)].(mt_rand(0, 1) === 1 ? ' ' : '');
            }

            try {
                $node = $parser->parse($formula, 0, 2, 2, 1);
                $evaluator->apply([[new SummaryFormula('F', $formula), $node]], self::CELLS);
            } catch (InvalidReportDefinition) {
                // the only way out
            }
        }

        $this->addToAssertionCount(1);
    }
}
