<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\Report;

final readonly class DeleteReport
{
    public function handle(Report $report): void
    {
        $report->delete();
    }
}
