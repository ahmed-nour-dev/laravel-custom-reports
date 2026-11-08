<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportFolder;

final readonly class RenameReportFolder
{
    public function handle(ReportFolder $folder, string $name): ReportFolder
    {
        $folder->update(['name' => $name]);

        return $folder;
    }
}
