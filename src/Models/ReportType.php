<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Database\Factories\ReportTypeFactory;
use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An admin-defined report type: a primary source, up to three child levels
 * (objects) and a field layout (SPEC §5).
 *
 * @property int $id
 * @property string|null $code
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property ReportTypeStatus $status
 * @property string $primary_source
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $deployed_at
 * @property bool $auto_add_fields
 * @property list<string>|null $known_fields primary-object field names as of the last auto-add sync
 * @property string|null $last_used_at only when loaded with withMax (ReportTypePicker)
 */
#[UseFactory(ReportTypeFactory::class)]
class ReportType extends Model
{
    /** @use HasFactory<ReportTypeFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'key', 'name', 'description', 'category', 'status', 'primary_source',
        'created_by', 'updated_by', 'deployed_at', 'auto_add_fields', 'known_fields',
    ];

    protected $casts = [
        'status' => ReportTypeStatus::class,
        'deployed_at' => 'datetime',
        'auto_add_fields' => 'boolean',
        'known_fields' => 'array',
    ];

    /**
     * Build the client-facing code for the given id (never expose the raw id).
     */
    public static function codeFromId(int $id): string
    {
        return 'RTY-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return HasMany<ReportTypeObject, $this> */
    public function objects(): HasMany
    {
        return $this->hasMany(ReportTypeObject::class)->orderBy('level');
    }

    /** @return HasMany<ReportTypeSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ReportTypeSection::class)->orderBy('position');
    }

    /** @return HasMany<ReportTypeField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ReportTypeField::class)->orderBy('position');
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'created_by');
    }

    /** @return BelongsTo<Model, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'updated_by');
    }
}
