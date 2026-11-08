<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\FormulaType;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\TestCase;

/** Phase 9a definition key (SPEC §14.6): summary formulas. */
final class ReportDefinitionFormulaTest extends TestCase
{
    public function test_a_formula_round_trips_with_its_defaults(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1, 'groupings' => [['field' => 'l0.region']],
            'formulas' => [['label' => 'Avg', 'expression' => 'a0 / RECORD_COUNT']],
        ]);

        $this->assertSame(FormulaType::NUMBER, $definition->formulas[0]->type);
        $this->assertSame(['label' => 'Avg', 'expression' => 'a0 / RECORD_COUNT', 'type' => 'number', 'decimals' => 2, 'down' => null, 'across' => null], $definition->toArray()['formulas'][0]);
        $this->assertEquals($definition, ReportDefinition::fromArray($definition->toArray()));
        $this->assertSame([], ReportDefinition::fromArray(['v' => 1])->formulas);
    }

    public function test_removing_a_grouping_or_aggregate_drops_the_formulas(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'groupings' => [['field' => 'l0.region']],
            'aggregates' => [['field' => 'l0.payment_terms', 'fn' => 'sum']],
            'formulas' => [['label' => 'Share', 'expression' => 'a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)']],
        ]);

        $this->assertCount(1, $definition->without(['l0.unrelated'])->formulas);
        $this->assertSame([], $definition->without(['l0.payment_terms'])->formulas);
        $this->assertSame([], $definition->without(['l0.region'])->formulas);
    }

    public function test_bad_shapes_are_refused(): void
    {
        $bad = [
            'not an object',
            ['expression' => 'a0'],
            ['label' => 'F'],
            ['label' => 'F', 'expression' => str_repeat('1', 3901)],
            ['label' => 'F', 'expression' => '1', 'type' => 'date'],
            ['label' => 'F', 'expression' => '1', 'decimals' => 7],
            ['label' => 'F', 'expression' => '1', 'down' => -1],
            ['label' => 'F', 'expression' => '1', 'across' => '0'],
        ];

        foreach ($bad as $formula) {
            try {
                ReportDefinition::fromArray(['v' => 1, 'formulas' => [$formula]]);
                $this->fail('accepted '.json_encode($formula));
            } catch (InvalidReportDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
