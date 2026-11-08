<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportScope;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\TestCase;

/** Phase 6 definition keys (SPEC §14.3): scope and two-field comparisons. */
final class ReportDefinitionScopeAndCompareTest extends TestCase
{
    public function test_an_older_definition_reads_as_all_with_value_filters(): void
    {
        $definition = ReportDefinition::fromArray(['v' => 1, 'columns' => ['l0.region'], 'filters' => [['n' => 1, 'field' => 'l0.region', 'op' => 'equals', 'value' => 'Cairo']]]);

        $this->assertSame(ReportScope::ALL, $definition->scope);
        $this->assertNull($definition->filters[0]->compareField);
        $this->assertArrayNotHasKey('compare_field', $definition->toArray()['filters'][0]);
    }

    public function test_scope_and_a_comparison_round_trip(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'columns' => ['l0.account_code'],
            'filters' => [['n' => 1, 'field' => 'l1.closed_at', 'op' => 'gt', 'value' => 'ignored', 'compare_field' => 'l1.due_at']],
            'scope' => 'my_team',
        ]);

        $this->assertSame(ReportScope::MY_TEAM, $definition->scope);
        $this->assertSame(['n' => 1, 'field' => 'l1.closed_at', 'op' => 'gt', 'value' => null, 'compare_field' => 'l1.due_at'], $definition->toArray()['filters'][0]);
        $this->assertSame('my_team', $definition->toArray()['scope']);
        $this->assertContains('l1.due_at', $definition->fieldKeys());
        $this->assertEquals($definition, ReportDefinition::fromArray($definition->toArray()));
    }

    public function test_removing_either_compared_field_drops_the_filter_and_keeps_the_scope(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'columns' => ['l0.account_code'],
            'filters' => [
                ['n' => 1, 'field' => 'l1.closed_at', 'op' => 'gt', 'compare_field' => 'l1.due_at'],
                ['n' => 2, 'field' => 'l0.region', 'op' => 'equals', 'value' => 'Cairo'],
            ],
            'scope' => 'mine',
        ]);

        $left = $definition->without(['l1.due_at']);
        $this->assertSame([2], array_map(static fn ($filter): int => $filter->n, $left->filters));
        $this->assertSame(ReportScope::MINE, $left->scope);
        $this->assertSame(ReportScope::MINE, $definition->forDetailExport()->scope);
    }

    public function test_an_unknown_scope_is_refused(): void
    {
        $this->expectException(InvalidReportDefinition::class);
        ReportDefinition::fromArray(['v' => 1, 'columns' => ['l0.region'], 'scope' => 'everyone']);
    }
}
