<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Enums\SubscriptionFrequency;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved report sent on a schedule (SPEC §14.5), `RSB-####`. Recipients are
 * roles and users (stored by id, exposed by code). Each send queues a
 * background run per recipient who may open the report, and emails them a
 * link — never rows.
 *
 * @property int $id
 * @property string|null $code
 * @property int $report_id
 * @property int|null $owner_id
 * @property SubscriptionFrequency $frequency
 * @property int|null $day
 * @property string $time
 * @property list<int> $role_ids
 * @property list<int> $user_ids
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 */
class ReportSubscription extends Model
{
    protected $fillable = ['code', 'report_id', 'owner_id', 'frequency', 'day', 'time', 'role_ids', 'user_ids', 'next_run_at', 'last_run_at'];

    protected $casts = [
        'frequency' => SubscriptionFrequency::class,
        'day' => 'integer',
        'role_ids' => 'array',
        'user_ids' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public static function codeFromId(int $id): string
    {
        return 'RSB-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'owner_id');
    }
}
