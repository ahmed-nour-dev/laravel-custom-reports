<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved report whose chart a user pinned to their dashboard (SPEC §14.5).
 * It never stores numbers: the widget runs the report as that user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $report_id
 * @property int $position
 */
class ReportPin extends Model
{
    protected $fillable = ['user_id', 'report_id', 'position'];

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::model());
    }
}
