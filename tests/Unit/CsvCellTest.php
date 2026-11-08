<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit;

use AhmedNour\CustomReports\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV export cells: text a spreadsheet would run as a formula gets a leading
 * apostrophe; numbers and plain text are untouched.
 */
final class CsvCellTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function cells(): iterable
    {
        yield 'equals' => ['=1+2', "'=1+2"];
        yield 'plus' => ['+cmd', "'+cmd"];
        yield 'minus text' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'negative int' => [-5, '-5'];
        yield 'negative float' => [-2.5, '-2.5'];
        yield 'negative decimal string' => ['-1200.50', '-1200.50'];
        yield 'plain text' => ['Alpha Cosmetics', 'Alpha Cosmetics'];
        yield 'date' => ['2026-11-02 10:00:00', '2026-11-02 10:00:00'];
        yield 'formula later in text' => ['A=B', 'A=B'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'true' => [true, '1'];
        yield 'array' => [['x'], ''];
    }

    #[DataProvider('cells')]
    public function test_cells(mixed $value, string $expected): void
    {
        $this->assertSame($expected, CsvCell::safe($value));
    }
}
