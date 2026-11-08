<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/**
 * Whose primary records a report shows (SPEC §14.3). Always applied on top of
 * the viewer's visibility, so it can only narrow: `mine` and `my_team` mean
 * records owned by the viewer, or by anyone on their team. Only sources whose
 * model has an owner offer them.
 */
enum ReportScope: string
{
    case ALL = 'all';
    case MINE = 'mine';
    case MY_TEAM = 'my_team';
}
