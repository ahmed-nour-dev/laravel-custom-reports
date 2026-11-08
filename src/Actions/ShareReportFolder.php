<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Enums\FolderAccess;
use AhmedNour\CustomReports\Models\ReportFolder;
use Illuminate\Support\Facades\DB;

/** Replaces who a folder is shared with: view or edit, per audience group (e.g. a role), by id. */
final readonly class ShareReportFolder
{
    /**
     * @param  list<array{group: int, access: FolderAccess}>  $shares
     */
    public function handle(ReportFolder $folder, array $shares): ReportFolder
    {
        DB::transaction(function () use ($folder, $shares): void {
            $folder->shares()->delete();

            foreach ($shares as $share) {
                $folder->shares()->updateOrCreate(
                    ['role_id' => $share['group']],
                    ['access' => $share['access']],
                );
            }
        });

        return $folder->load('shares');
    }
}
