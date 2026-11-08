<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** How a date grouping buckets its values (SPEC §8.3). */
enum DateGranularity: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case QUARTER = 'quarter';
    case YEAR = 'year';
}
