<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Data;

use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;

/**
 * A joined report's definition (SPEC §14.7), `{"v": 1, "blocks": […]}`: up
 * to MAX_BLOCKS summary blocks, each on its own report type, merged by the
 * value of the grouping they share. fromArray() checks the shape; the
 * JoinedValidator checks each block against its type and the shared grouping.
 */
final readonly class JoinedDefinition
{
    public const int MAX_BLOCKS = 5;

    /**
     * @param  list<JoinedBlock>  $blocks
     */
    public function __construct(public array $blocks) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['v'] ?? ReportDefinition::VERSION;
        if ($version !== ReportDefinition::VERSION) {
            throw InvalidReportDefinition::unsupportedVersion($version);
        }

        $blocks = $data['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks) || $blocks === [] || count($blocks) > self::MAX_BLOCKS) {
            throw InvalidReportDefinition::malformed('a joined report needs 1 to '.self::MAX_BLOCKS.' blocks.');
        }

        return new self(array_map(static function (mixed $block, int $index): JoinedBlock {
            $n = $index + 1;
            if (! is_array($block) || ! is_string($block['report_type'] ?? null) || $block['report_type'] === '' || ! is_array($block['definition'] ?? null)) {
                throw InvalidReportDefinition::badJoinedBlock($n, 'it needs a report type and a definition.');
            }

            $label = is_string($block['label'] ?? null) && trim($block['label']) !== '' ? mb_substr(trim($block['label']), 0, 100) : "Block {$n}";

            return new JoinedBlock($block['report_type'], $label, ReportDefinition::fromArray($block['definition']));
        }, $blocks, array_keys($blocks)));
    }

    /**
     * @return array{v: int, blocks: list<array{report_type: string, label: string, definition: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return ['v' => ReportDefinition::VERSION, 'blocks' => array_map(static fn (JoinedBlock $block): array => $block->toArray(), $this->blocks)];
    }

    /** @return list<string> the report type codes, in block order */
    public function reportTypes(): array
    {
        return array_map(static fn (JoinedBlock $block): string => $block->reportType, $this->blocks);
    }
}
