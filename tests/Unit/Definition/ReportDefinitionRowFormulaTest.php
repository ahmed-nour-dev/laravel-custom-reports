<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Data\RowFormula;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\TestCase;

/** Phase 9b definition keys (SPEC §14.6): the row formula and highlights. */
final class ReportDefinitionRowFormulaTest extends TestCase
{
    public function test_they_round_trip_and_the_formula_s_fields_count_as_used(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'columns' => ['l1.sample_code', RowFormula::KEY],
            'groupings' => [['field' => 'l0.region']],
            'row_formula' => ['label' => 'Value', 'expression' => 'L1.Quantity * l1.target_retail_price'],
            'highlights' => [['target' => 'record_count', 'low' => 1, 'high' => 5]],
        ]);

        $this->assertSame(['label' => 'Value', 'expression' => 'L1.Quantity * l1.target_retail_price', 'type' => 'number', 'decimals' => 2], $definition->toArray()['row_formula']);
        $this->assertSame([['target' => 'record_count', 'low' => 1, 'high' => 5, 'colors' => ['danger', 'warning', 'success']]], $definition->toArray()['highlights']);
        $this->assertEquals($definition, ReportDefinition::fromArray($definition->toArray()));

        $keys = $definition->fieldKeys();
        $this->assertContains('l1.quantity', $keys);
        $this->assertContains('l1.target_retail_price', $keys);
        $this->assertNotContains(RowFormula::KEY, $keys);
    }

    public function test_removing_a_field_it_reads_drops_it_and_its_uses(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'columns' => ['l1.sample_code', RowFormula::KEY],
            'groupings' => [['field' => 'l0.region']],
            'aggregates' => [['field' => RowFormula::KEY, 'fn' => 'sum']],
            'sort' => [['field' => RowFormula::KEY, 'dir' => 'desc']],
            'row_formula' => ['label' => 'Value', 'expression' => 'l1.quantity * 2'],
            'highlights' => [['target' => 'a0', 'low' => 1, 'high' => 5]],
        ]);

        $this->assertNotNull($definition->without(['l0.unrelated'])->rowFormula);

        $left = $definition->without(['l1.quantity']);
        $this->assertNull($left->rowFormula);
        $this->assertSame(['l1.sample_code'], $left->columns);
        $this->assertSame([], $left->aggregates);
        $this->assertSame([], $left->sort);
        $this->assertSame([], $left->highlights);   // its target went with the aggregate
    }

    public function test_bad_shapes_are_refused(): void
    {
        $bad = [
            ['row_formula' => 'l1.quantity'],
            ['row_formula' => ['label' => 'F']],
            ['row_formula' => ['label' => 'F', 'expression' => str_repeat('1', 1001)]],
            ['row_formula' => ['label' => 'F', 'expression' => '1', 'decimals' => 8]],
            ['highlights' => [['target' => 'a0', 'low' => 'low', 'high' => 1]]],
            ['highlights' => [['target' => 'a0', 'low' => 1, 'high' => 2, 'colors' => ['danger']]]],
        ];

        foreach ($bad as $data) {
            try {
                ReportDefinition::fromArray(['v' => 1, ...$data]);
                $this->fail('accepted '.json_encode($data));
            } catch (InvalidReportDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
