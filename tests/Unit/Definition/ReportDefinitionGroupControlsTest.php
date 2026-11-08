<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Definition;

use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Phase 5 definition keys (SPEC §14.2): shape, round trip, derived format, and removal of fields. */
final class ReportDefinitionGroupControlsTest extends TestCase
{
    public function test_a_phase_3_definition_reads_with_the_defaults(): void
    {
        $definition = ReportDefinition::fromArray(['v' => 1, 'columns' => ['l0.region'], 'groupings' => [['field' => 'l0.region']]]);

        $this->assertSame([], $definition->groupingsAcross);
        $this->assertNull($definition->sortAggregate);
        $this->assertNull($definition->topN);
        $this->assertFalse($definition->hasDetailRows);
        $this->assertTrue($definition->showSubtotals);
        $this->assertTrue($definition->showGrandTotal);
    }

    public function test_it_round_trips(): void
    {
        $data = [
            'v' => 1,
            'columns' => ['l1.sample_code'],
            'groupings' => [['field' => 'l0.region', 'granularity' => null]],
            'groupings_across' => [['field' => 'l1.created_at', 'granularity' => 'quarter']],
            'aggregates' => [['field' => 'l1.quantity', 'fn' => 'sum']],
            'sort_aggregate' => ['depth' => 0, 'aggregate' => 'a0', 'dir' => 'asc'],
            'top_n' => ['limit' => 5, 'aggregate' => 'record_count', 'dir' => 'desc'],
            'has_detail_rows' => false,
            'show_subtotals' => false,
            'show_grand_total' => false,
        ];

        $array = ReportDefinition::fromArray($data)->toArray();

        foreach ($data as $key => $value) {
            $this->assertSame($value, $array[$key], $key);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, ReportFormat}>
     */
    public static function formats(): array
    {
        return [
            'columns only' => [['columns' => ['l0.region']], ReportFormat::TABULAR],
            'row groupings' => [['groupings' => [['field' => 'l0.region']]], ReportFormat::SUMMARY],
            'aggregates only' => [['aggregates' => [['field' => 'l1.quantity', 'fn' => 'sum']]], ReportFormat::SUMMARY],
            'column groupings' => [['groupings_across' => [['field' => 'l1.status']]], ReportFormat::MATRIX],
            'both axes' => [['groupings' => [['field' => 'l0.region']], 'groupings_across' => [['field' => 'l1.status']]], ReportFormat::MATRIX],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('formats')]
    public function test_the_format_follows_the_definition(array $data, ReportFormat $expected): void
    {
        $this->assertSame($expected, ReportFormat::fromDefinition(ReportDefinition::fromArray(['v' => 1, ...$data])));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformed(): array
    {
        return [
            'aggregate ref with sql' => [['sort_aggregate' => ['depth' => 0, 'aggregate' => 'a0; drop']]],
            'depth not an int' => [['sort_aggregate' => ['depth' => '0', 'aggregate' => 'a0']]],
            'top_n without limit' => [['top_n' => ['aggregate' => 'a0']]],
            'detail rows not a bool' => [['has_detail_rows' => 1]],
            'across not a list' => [['groupings_across' => ['field' => 'l0.region']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('malformed')]
    public function test_bad_shapes_are_refused(array $data): void
    {
        $this->expectException(InvalidReportDefinition::class);

        ReportDefinition::fromArray(['v' => 1, ...$data]);
    }

    public function test_removing_fields_remaps_or_drops_the_group_controls(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'columns' => ['l1.sample_code'],
            'groupings' => [['field' => 'l0.region'], ['field' => 'l0.account_code']],
            'groupings_across' => [['field' => 'l1.status']],
            'aggregates' => [['field' => 'l0.payment_terms', 'fn' => 'max'], ['field' => 'l1.quantity', 'fn' => 'sum']],
            'sort_aggregate' => ['depth' => 1, 'aggregate' => 'a1'],
            'top_n' => ['limit' => 3, 'aggregate' => 'a1'],
            'has_detail_rows' => true,
        ]);

        // a0 goes, so a1 becomes a0; region (depth 0) goes, so depth 1 becomes 0 and Top-N lost its grouping.
        $kept = $definition->without(['l0.payment_terms', 'l0.region', 'l1.status']);
        $this->assertSame([], $kept->groupingsAcross);
        $this->assertSame(['depth' => 0, 'aggregate' => 'a0', 'dir' => 'desc'], $kept->sortAggregate?->toArray());
        $this->assertNull($kept->topN);
        $this->assertTrue($kept->hasDetailRows);
        $this->assertSame(ReportFormat::SUMMARY, ReportFormat::fromDefinition($kept));

        // The sorted aggregate itself goes.
        $this->assertNull($definition->without(['l1.quantity'])->sortAggregate);
        $this->assertNull($definition->without(['l1.quantity'])->topN);
        $this->assertSame(['limit' => 3, 'aggregate' => 'a1', 'dir' => 'desc'], $definition->without(['l1.status'])->topN?->toArray());

        // Detail rows need a column to show.
        $this->assertFalse($definition->without(['l1.sample_code'])->hasDetailRows);
    }

    public function test_detail_export_includes_the_column_groupings(): void
    {
        $definition = ReportDefinition::fromArray([
            'v' => 1,
            'groupings' => [['field' => 'l0.region']],
            'groupings_across' => [['field' => 'l1.status']],
            'aggregates' => [['field' => 'l1.quantity', 'fn' => 'sum']],
        ])->forDetailExport();

        $this->assertSame(['l0.region', 'l1.status', 'l1.quantity'], $definition->columns);
        $this->assertSame([], $definition->groupingsAcross);
    }
}
