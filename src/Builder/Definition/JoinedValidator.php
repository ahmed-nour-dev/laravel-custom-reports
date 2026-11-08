<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Definition;

use AhmedNour\CustomReports\Data\Bucket;
use AhmedNour\CustomReports\Data\JoinedBlock;
use AhmedNour\CustomReports\Data\JoinedDefinition;
use AhmedNour\CustomReports\Enums\DateGranularity;
use AhmedNour\CustomReports\Enums\FieldType;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Queries\ReportTypePicker;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Checks a joined report (SPEC §14.7). Each block is a summary with exactly
 * one row grouping, on a report type the viewer may build on, valid as its
 * own definition (DefinitionValidator, up to MAX_BLOCK_FORMULAS formulas).
 * Every block's grouping must reach the **same registry source and field**
 * — through the primary object or a lookup — with the same date grouping,
 * or saving is refused (`report_definition_joined_no_common_grouping`).
 */
final readonly class JoinedValidator
{
    public const int MAX_BLOCK_FORMULAS = 10;

    public function __construct(
        private DefinitionValidator $validator,
        private ReportTypePicker $picker,
    ) {}

    /**
     * A saved joined report as it reads today: each block minus the fields an
     * admin removed from its type since, and those keys ("Block 2: l1.x").
     *
     * @return array{JoinedDefinition, list<string>}
     */
    public function withoutRemoved(JoinedDefinition $definition): array
    {
        $dropped = [];
        $blocks = [];
        foreach ($definition->blocks as $index => $block) {
            $type = ReportType::query()->where('code', $block->reportType)->first();
            $keys = $type instanceof ReportType ? $this->validator->droppedKeys($type, $block->definition) : [];
            foreach ($keys as $key) {
                $dropped[] = 'Block '.($index + 1).': '.$key;
            }
            $blocks[] = $keys === [] ? $block : new JoinedBlock($block->reportType, $block->label, $block->definition->without($keys));
        }

        return [new JoinedDefinition($blocks), $dropped];
    }

    /**
     * $dropRemoved: for a saved report, first drop the fields an admin removed
     * from a block's type since (as a saved summary does).
     *
     * @return list<array{block: JoinedBlock, type: ReportType, validated: ValidatedDefinition, dropped: list<string>}>
     */
    public function validate(JoinedDefinition $definition, Authenticatable $viewer, bool $dropRemoved = false): array
    {
        $blocks = [];
        $shared = null;

        foreach ($definition->blocks as $index => $block) {
            $n = $index + 1;
            $type = ReportType::query()->where('code', $block->reportType)->first();

            if (! $type instanceof ReportType || ! $this->picker->isAvailableTo($type, $viewer)) {
                throw InvalidReportDefinition::badJoinedBlock($n, "there is no report type {$block->reportType} you can build on.");
            }

            $summary = $block->definition;
            $dropped = $dropRemoved ? $this->validator->droppedKeys($type, $summary) : [];
            $summary = $dropped === [] ? $summary : $summary->without($dropped);

            if (count($summary->groupings) !== 1 || $summary->groupingsAcross !== []) {
                throw InvalidReportDefinition::badJoinedBlock($n, 'a block groups its rows by exactly one field (no column groupings).');
            }
            if (Bucket::isBucketKey($summary->groupings[0]->field)) {
                throw InvalidReportDefinition::badJoinedBlock($n, 'a block groups by a field, not a bucket.');
            }
            if ($summary->hasDetailRows || $summary->chart !== null) {
                throw InvalidReportDefinition::badJoinedBlock($n, 'a block has no detail rows or chart.');
            }

            try {
                $validated = $this->validator->validate($type, $summary, ReportFormat::SUMMARY, self::MAX_BLOCK_FORMULAS);
            } catch (InvalidReportDefinition $e) {
                throw InvalidReportDefinition::badJoinedBlock($n, $e->getMessage());
            }

            $grouping = $validated->definition->groupings[0];
            $resolved = $validated->field($grouping->field);
            $reaches = [
                'field' => $resolved->source->key().'.'.$resolved->field->name,
                // A date grouped without a granularity groups by day.
                'granularity' => ($grouping->granularity
                    ?? (in_array($resolved->field->type, [FieldType::DATE, FieldType::DATETIME], true) ? DateGranularity::DAY : null))?->value,
                'label' => $resolved->label(),
            ];

            if ($shared === null) {
                $shared = $reaches;
            } elseif ($reaches['field'] !== $shared['field']) {
                throw InvalidReportDefinition::joinedNoCommonGrouping("block {$n} groups by \"{$reaches['label']}\" ({$reaches['field']}), block 1 by \"{$shared['label']}\" ({$shared['field']}).");
            } elseif ($reaches['granularity'] !== $shared['granularity']) {
                throw InvalidReportDefinition::joinedNoCommonGrouping("block {$n} groups its dates by ".($reaches['granularity'] ?? 'day').', block 1 by '.($shared['granularity'] ?? 'day').'.');
            }

            $blocks[] = ['block' => $block, 'type' => $type, 'validated' => $validated, 'dropped' => $dropped];
        }

        return $blocks;
    }
}
