<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Data\ExportResult;
use Illuminate\Support\Str;

/**
 * A joined report's result (RunJoinedReport) as exports (SPEC §14.7): one
 * section per block for CSV — the shared grouping, then the block's record
 * count, totals and formulas, and its total row — or one wide table for PDF,
 * every block's columns side by side.
 */
final readonly class ExportJoinedReport
{
    /**
     * @param  array<string, mixed>  $result
     * @return list<array{label: string, columns: list<string>, rows: list<list<mixed>>}>
     */
    public function sections(array $result): array
    {
        $sections = [];
        foreach ($result['blocks'] as $block) {
            $metrics = $this->metrics($block);
            $prefix = 'B'.$block['block'].':';
            $rows = [];

            foreach ($result['groups'] as $group) {
                $cell = $result['fact_map'][$prefix.$group['key'].'!'.FactMap::TOTAL] ?? null;
                if ($cell !== null) {
                    $rows[] = [$this->groupText($group), ...array_map(static fn (array $metric): mixed => $metric['value']($cell), $metrics)];
                }
            }
            $total = $result['fact_map'][$prefix.FactMap::TOTAL.'!'.FactMap::TOTAL] ?? null;
            if ($total !== null) {
                $rows[] = ['Total', ...array_map(static fn (array $metric): mixed => $metric['value']($total), $metrics)];
            }

            $sections[] = [
                'label' => (string) $block['label'],
                'columns' => [(string) ($result['grouping']['label'] ?? 'Group'), ...array_column($metrics, 'label')],
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function toExportResult(array $result, string $title): ExportResult
    {
        $columns = ['group' => (string) ($result['grouping']['label'] ?? 'Group')];
        $blocks = [];
        foreach ($result['blocks'] as $block) {
            foreach ($this->metrics($block) as $metric) {
                $columns['b'.$block['block'].'_'.$metric['id']] = $block['label'].' · '.$metric['label'];
            }
            $blocks[] = $block;
        }

        $row = function (string $key, string $label) use ($result, $blocks): array {
            $values = ['group' => $label];
            foreach ($blocks as $block) {
                $cell = $result['fact_map']['B'.$block['block'].':'.$key.'!'.FactMap::TOTAL] ?? null;
                foreach ($this->metrics($block) as $metric) {
                    $values['b'.$block['block'].'_'.$metric['id']] = $cell === null ? null : $metric['value']($cell);
                }
            }

            return $values;
        };

        $rows = array_map(fn (array $group): array => $row((string) $group['key'], $this->groupText($group)), $result['groups']);
        $rows[] = $row(FactMap::TOTAL, 'Total');

        return new ExportResult(title: $title, columns: $columns, rows: array_values($rows));
    }

    /**
     * What a block shows per group: the record count, its totals, its formulas.
     *
     * @param  array<string, mixed>  $block
     * @return list<array{id: string, label: string, value: callable(array<string, mixed>): mixed}>
     */
    private function metrics(array $block): array
    {
        $metrics = [['id' => 'record_count', 'label' => 'Record Count', 'value' => static fn (array $cell): mixed => $cell['record_count'] ?? 0]];

        foreach ($block['aggregates'] as $aggregate) {
            $metrics[] = [
                'id' => (string) $aggregate['column'],
                'label' => Str::headline((string) $aggregate['fn']).' · '.$aggregate['label'],
                'value' => static fn (array $cell): mixed => $cell['aggregates'][$aggregate['column']] ?? null,
            ];
        }
        foreach ($block['formulas'] as $formula) {
            $metrics[] = [
                'id' => (string) $formula['column'],
                'label' => (string) $formula['label'],
                'value' => static fn (array $cell): mixed => $cell['formulas'][$formula['column']] ?? null,
            ];
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupText(array $group): string
    {
        return (string) ($group['label'] ?? $group['value'] ?? '—');
    }
}
