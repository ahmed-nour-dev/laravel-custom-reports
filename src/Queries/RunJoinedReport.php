<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Queries;

use AhmedNour\CustomReports\Builder\Definition\ValidatedDefinition;
use AhmedNour\CustomReports\Builder\FactMap;
use AhmedNour\CustomReports\Data\JoinedBlock;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Runs a joined report (SPEC §14.7): every block as its own summary, with
 * the viewer's visibility on its own type, then merged by the value of the
 * shared grouping into one fact map with a block prefix —
 * `B{n}:{group}!T` for a group, `B{n}:T!T` for the block's total. A group
 * one block doesn't have is simply missing from that block's cells. Groups
 * are ordered by value, blanks last.
 */
final readonly class RunJoinedReport
{
    public function __construct(private SummarizeCustomReport $summarize) {}

    /**
     * @param  list<array{block: JoinedBlock, type: ReportType, validated: ValidatedDefinition, dropped: list<string>}>  $blocks  from JoinedValidator
     * @return array<string, mixed>
     */
    public function handle(array $blocks, Authenticatable $viewer): array
    {
        $groups = [];
        $results = [];
        foreach ($blocks as $index => $entry) {
            $result = $this->summarize->handle($entry['type'], $entry['validated'], $viewer);
            $results[$index] = $result;

            foreach ($result['groupings_down'] as $node) {
                $id = $this->id($node['value'] ?? null);
                $groups[$id] ??= ['value' => $node['value'] ?? null, 'label' => $node['label'] ?? null];
            }
        }

        uasort($groups, static function (array $a, array $b): int {
            if ($a['value'] === null || $b['value'] === null) {
                return ($a['value'] === null) <=> ($b['value'] === null);
            }

            return is_numeric($a['value']) && is_numeric($b['value'])
                ? $a['value'] <=> $b['value']
                : strnatcasecmp((string) $a['value'], (string) $b['value']);
        });

        $position = [];
        $list = [];
        foreach ($groups as $id => $group) {
            $position[$id] = (string) count($list);
            $list[] = ['key' => $position[$id], ...$group];
        }

        $cells = [];
        $described = [];
        $truncated = false;
        foreach ($results as $index => $result) {
            $prefix = 'B'.($index + 1).':';
            /** @var array<string, array<string, mixed>> $map */
            $map = $result['fact_map'];

            foreach ($result['groupings_down'] as $node) {
                $cell = $map[$node['key'].'!'.FactMap::TOTAL] ?? null;
                if ($cell !== null) {
                    $cells[$prefix.$position[$this->id($node['value'] ?? null)].'!'.FactMap::TOTAL] = $cell;
                }
            }
            if (isset($map[FactMap::TOTAL.'!'.FactMap::TOTAL])) {
                $cells[$prefix.FactMap::TOTAL.'!'.FactMap::TOTAL] = $map[FactMap::TOTAL.'!'.FactMap::TOTAL];
            }

            $entry = $blocks[$index];
            $truncated = $truncated || (bool) ($result['meta']['truncated'] ?? false);
            $described[] = [
                'block' => $index + 1,
                'label' => $entry['block']->label,
                'report_type' => ['code' => $entry['type']->code, 'name' => $entry['type']->name],
                'levels' => $result['levels'],
                'aggregates' => $result['aggregates'],
                'formulas' => $result['formulas'],
                'highlights' => $result['highlights'],
                'dropped' => $entry['dropped'],
            ];
        }

        return [
            'format' => 'joined',
            'grouping' => $results[0]['axes']['down'][0] ?? null,
            'groups' => $list,
            'blocks' => $described,
            'fact_map' => $cells,
            'meta' => ['truncated' => $truncated],
        ];
    }

    /** Groups match across blocks by value: 5 and "5" are one group, null its own. */
    private function id(mixed $value): string
    {
        return $value === null ? "\0null" : (string) $value;
    }
}
