<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ChartType;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\TestCase;

/** Phase 8 definition key (SPEC §14.5): the chart. */
final class ReportDefinitionChartTest extends TestCase
{
    public function test_it_round_trips_and_defaults_to_the_record_count(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1, 'groupings' => [['field' => 'l0.region']],
            'chart' => ['type' => 'gauge', 'target' => 12.5],
        ]);

        $this->assertSame(ChartType::GAUGE, $definition->chart?->type);
        $this->assertSame(['type' => 'gauge', 'value' => 'record_count', 'x' => null, 'series' => null, 'target' => 12.5], $definition->toArray()['chart']);
        $this->assertEquals($definition, ReportDefinition::fromArray($definition->toArray()));
        $this->assertNull(ReportDefinition::fromArray(['v' => 1, 'columns' => ['l0.region']])->chart);
    }

    public function test_removing_a_field_the_chart_uses_drops_it(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'groupings' => [['field' => 'l0.region'], ['field' => 'l0.status']],
            'aggregates' => [['field' => 'l0.payment_terms', 'fn' => 'sum']],
            'chart' => ['type' => 'stacked_bar', 'x' => 'g0', 'series' => 'g1', 'value' => 'a0'],
        ]);

        $this->assertNotNull($definition->without(['l0.unrelated'])->chart);
        $this->assertNull($definition->without(['l0.status'])->chart);
        $this->assertNull($definition->without(['l0.payment_terms'])->chart);
    }

    public function test_bad_shapes_are_refused(): void
    {
        // A form sends numbers as text: "150" is a target, "lots" is not.
        $this->assertSame(150, ReportDefinition::fromArray(['v' => 1, 'chart' => ['type' => 'gauge', 'target' => '150']])->chart?->target);

        foreach ([['type' => 'radar'], 'bar', ['type' => 'bar', 'value' => 'total'], ['type' => 'gauge', 'target' => 'lots']] as $chart) {
            try {
                ReportDefinition::fromArray(['v' => 1, 'chart' => $chart]);
                $this->fail('accepted '.json_encode($chart));
            } catch (InvalidReportDefinition) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
