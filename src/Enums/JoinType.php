<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * How a child level joins its parent in a report type's chain.
 * INNER = "must have at least one", LEFT = "may or may not have".
 */
enum JoinType: string
{
    case INNER = 'inner';
    case LEFT = 'left';
}
