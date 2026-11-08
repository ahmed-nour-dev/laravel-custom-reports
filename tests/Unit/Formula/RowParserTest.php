<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Formula;

use AhmedNour\CustomReports\Builder\Formula\FormulaNode;
use AhmedNour\CustomReports\Builder\Formula\RowParser;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The row formula's grammar (SPEC §14.6, 9b): what parses, what doesn't, and fuzzing. */
final class RowParserTest extends TestCase
{
    public function test_it_reads_fields_numbers_and_functions(): void
    {
        $node = (new RowParser)->parse('IF(l1.quantity > 100, ROUND(l1.quantity * L1.Target_Retail_Price, 2), COALESCE(l1.quantity, 0))');

        $this->assertSame(FormulaNode::CALL, $node->kind);
        $this->assertSame('IF', $node->value);
        $this->assertSame(['l1.quantity', 'l1.target_retail_price'], RowParser::fields($node));
        $this->assertSame(['l1.sent_at', 'l1.request_date'], RowParser::fields((new RowParser)->parse('DATEDIFF(l1.sent_at, l1.request_date)')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'empty' => [''];
        yield 'not a field' => ['quantity * 2'];
        yield 'a lookup key' => ['l1@account.region'];
        yield 'too deep a level' => ['l4.quantity'];
        yield 'a summary total' => ['a0 + 1'];
        yield 'a summary function' => ['PARENTGROUPVAL(l1.quantity, g0)'];
        yield 'unknown function' => ['SQRT(l1.quantity)'];
        yield 'round places as a field' => ['ROUND(l1.quantity, l1.iteration)'];
        yield 'round places too many' => ['ROUND(l1.quantity, 11)'];
        yield 'round places a fraction' => ['ROUND(l1.quantity, 1.5)'];
        yield 'datediff of an expression' => ['DATEDIFF(l1.sent_at + 1, l1.request_date)'];
        yield 'if arity' => ['IF(l1.quantity, 1)'];
        yield 'injection' => ['l1.quantity); DROP TABLE accounts; --'];
        yield 'a string' => ["'x'"];
        yield 'unclosed' => ['(l1.quantity + 1'];
        yield 'too long' => [str_repeat('l1.quantity+', 100).'1'];
        yield 'too deep' => [str_repeat('(', 40).'l1.quantity'.str_repeat(')', 40)];
    }

    #[DataProvider('refusals')]
    public function test_refusals(string $formula): void
    {
        $this->expectException(InvalidReportDefinition::class);
        (new RowParser)->parse($formula);
    }

    public function test_fuzzed_input_only_ever_fails_cleanly(): void
    {
        $alphabet = ['l1.quantity', 'l0.region', 'l9.x', 'IF', 'COALESCE', 'ROUND', 'ABS', 'DATEDIFF', 'a0', '(', ')', ',', '+', '-', '*', '/',
            '<', '>=', '=', '<>', '0', '2', '1.5', '11', '.', ';', "'", '"', '`', '@', 'é', ' '];
        $parser = new RowParser;

        for ($i = 0; $i < 2000; $i++) {
            $formula = '';
            for ($j = 0, $n = mt_rand(0, 20); $j < $n; $j++) {
                $formula .= $alphabet[array_rand($alphabet)].(mt_rand(0, 1) === 1 ? ' ' : '');
            }

            try {
                $parser->parse($formula);
            } catch (InvalidReportDefinition) {
                // the only way out
            }
        }

        $this->addToAssertionCount(1);
    }
}
