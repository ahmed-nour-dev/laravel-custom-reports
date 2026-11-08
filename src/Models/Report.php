<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Data\JoinedDefinition;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Database\Factories\ReportFactory;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A saved custom report: a report type plus a definition (SPEC §8.1). Private
 * to its owner unless it sits in a folder shared by role.
 *
 * @property int $id
 * @property string|null $code
 * @property int $report_type_id
 * @property int|null $report_folder_id
 * @property string $name
 * @property string|null $description
 * @property ReportFormat $format
 * @property array<string, mixed> $definition
 * @property int|null $owner_id
 * @property Carbon|null $last_run_at
 */
#[UseFactory(ReportFactory::class)]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'report_type_id', 'report_folder_id', 'name', 'description', 'format', 'definition', 'owner_id', 'last_run_at',
    ];

    protected $casts = [
        'format' => ReportFormat::class,
        'definition' => 'array',
        'last_run_at' => 'datetime',
    ];

    /**
     * Build the client-facing code for the given id (never expose the raw id).
     */
    public static function codeFromId(int $id): string
    {
        return 'RPT-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** A joined report's blocks live in joinedDefinition() instead (SPEC §14.7). */
    public function definition(): ReportDefinition
    {
        return ReportDefinition::fromArray($this->format === ReportFormat::JOINED ? ['v' => ReportDefinition::VERSION] : $this->definition);
    }

    public function joinedDefinition(): JoinedDefinition
    {
        return JoinedDefinition::fromArray($this->definition);
    }

    /** @return BelongsTo<ReportType, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    /** @return BelongsTo<ReportFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ReportFolder::class, 'report_folder_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'owner_id');
    }
}
