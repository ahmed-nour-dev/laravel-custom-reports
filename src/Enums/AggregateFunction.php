<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * Summary aggregates (SPEC §7.4). SUM and AVG need a numeric field that does
 * not repeat; a repeated field only allows COUNT_DISTINCT, MIN and MAX.
 */
enum AggregateFunction: string
{
    case SUM = 'sum';
    case AVG = 'avg';
    case MIN = 'min';
    case MAX = 'max';
    case COUNT_DISTINCT = 'count_distinct';

    public function needsNumeric(): bool
    {
        return $this === self::SUM || $this === self::AVG;
    }

    public function allowedOnRepeated(): bool
    {
        return ! $this->needsNumeric();
    }
}
