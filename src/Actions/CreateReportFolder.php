<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final readonly class CreateReportFolder
{
    public function handle(string $name, Authenticatable $owner): ReportFolder
    {
        return DB::transaction(function () use ($name, $owner): ReportFolder {
            $folder = ReportFolder::query()->create(['name' => $name, 'owner_id' => Users::id($owner)]);
            $folder->update(['code' => ReportFolder::codeFromId($folder->id)]);

            return $folder;
        });
    }
}
