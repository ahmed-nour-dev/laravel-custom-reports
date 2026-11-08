<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

use AhmedNour\CustomReports\Data\ReportDefinition;

enum ReportFormat: string
{
    case TABULAR = 'tabular';
    case SUMMARY = 'summary';
    case MATRIX = 'matrix';
    /** Up to 5 summary blocks, each on its own report type, side by side under one shared grouping (SPEC §14.7). */
    case JOINED = 'joined';

    /**
     * The format a definition implies (SPEC §14.2): column groupings make a
     * matrix, row groupings or aggregates a summary, anything else a table.
     * Saving and previewing use this and ignore what the client sends.
     */
    public static function fromDefinition(ReportDefinition $definition): self
    {
        return match (true) {
            $definition->groupingsAcross !== [] => self::MATRIX,
            $definition->groupings !== [], $definition->aggregates !== [] => self::SUMMARY,
            default => self::TABULAR,
        };
    }

    /** Summary or matrix — one definition on one type. A joined report runs its own way. */
    public function isGrouped(): bool
    {
        return $this === self::SUMMARY || $this === self::MATRIX;
    }
}
