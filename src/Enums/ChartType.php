<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** The chart a summary or matrix report draws from its fact map (SPEC §14.5). */
enum ChartType: string
{
    case BAR = 'bar';
    case COLUMN = 'column';
    case STACKED_BAR = 'stacked_bar';
    case LINE = 'line';
    case PIE = 'pie';
    case DONUT = 'donut';
    case FUNNEL = 'funnel';
    case METRIC = 'metric';
    case GAUGE = 'gauge';

    /** One number (the grand total), no grouping on an axis. */
    public function isSingleValue(): bool
    {
        return $this === self::METRIC || $this === self::GAUGE;
    }

    /** Slices or steps of one whole: a second grouping (series) means nothing. */
    public function isOneSeries(): bool
    {
        return in_array($this, [self::PIE, self::DONUT, self::FUNNEL], true);
    }
}
