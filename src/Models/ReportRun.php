<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Enums\ReportRunKind;
use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A background run or export of a saved report for one viewer (SPEC §14.4),
 * `RRN-####`, bound by code. The definition is the one that was queued, so a
 * later edit of the report doesn't change what the run computes.
 *
 * @property int $id
 * @property string|null $code
 * @property int|null $report_id
 * @property int $report_type_id
 * @property int|null $report_subscription_id set when a scheduled send queued it
 * @property int $viewer_id
 * @property ReportRunKind $kind
 * @property ReportFormat $format
 * @property array<string, mixed> $definition
 * @property string $definition_hash
 * @property ReportRunStatus $status
 * @property string|null $result_path
 * @property int|null $row_count
 * @property bool $truncated
 * @property string|null $error_slug
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class ReportRun extends Model
{
    protected $fillable = [
        'code', 'report_id', 'report_type_id', 'report_subscription_id', 'viewer_id', 'kind', 'format', 'definition', 'definition_hash',
        'status', 'result_path', 'row_count', 'truncated', 'error_slug', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $casts = [
        'kind' => ReportRunKind::class,
        'format' => ReportFormat::class,
        'definition' => 'array',
        'status' => ReportRunStatus::class,
        'truncated' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Build the client-facing code for the given id (never expose the raw id).
     */
    public static function codeFromId(int $id): string
    {
        return 'RRN-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** Done, failed or queued as stored — except a done run past its expiry, which reads as expired. */
    public function currentStatus(): ReportRunStatus
    {
        return $this->status === ReportRunStatus::DONE && $this->expires_at?->isPast() ? ReportRunStatus::EXPIRED : $this->status;
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<ReportType, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    /** @return BelongsTo<ReportSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ReportSubscription::class, 'report_subscription_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'viewer_id');
    }
}
