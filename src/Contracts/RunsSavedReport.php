<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Saved report charts for the dashboard (SPEC §14.5), the one thing
 * Widgets (tier 12) asks of Reports. Every chart is run as $viewer, with
 * their visibility; a report they can no longer open — or that lost its
 * chart, or took too long — comes back as not available, never with someone
 * else's numbers.
 */
interface RunsSavedReport
{
    /**
     * The charts $viewer pinned, in their order.
     *
     * @return list<array{code: string, name: string|null, available: bool, reason: string|null, chart: array<string, mixed>|null}>
     */
    public function dashboardCharts(Authenticatable $viewer): array;
}
