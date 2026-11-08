<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** queued → running → done | failed; a done run past its expiry reads as expired. */
enum ReportRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
}
