<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Enums\FolderAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $report_folder_id
 * @property int $role_id
 * @property FolderAccess $access
 */
class ReportFolderShare extends Model
{
    protected $fillable = ['report_folder_id', 'role_id', 'access'];

    protected $casts = [
        'access' => FolderAccess::class,
    ];

    /** @return BelongsTo<ReportFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ReportFolder::class, 'report_folder_id');
    }

    /**
     * The audience group the folder is shared with — the app's model named by
     * `custom-reports.audience_model` (e.g. a Role model).
     *
     * @return BelongsTo<Model, $this>
     */
    public function role(): BelongsTo
    {
        $model = config('custom-reports.audience_model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new LogicException('Set custom-reports.audience_model to share folders with groups.');
        }

        return $this->belongsTo($model, 'role_id');
    }
}
