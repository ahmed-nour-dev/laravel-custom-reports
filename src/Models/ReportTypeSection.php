<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of fields in a report type's layout.
 *
 * @property int $id
 * @property int $report_type_id
 * @property string $name_en
 * @property string|null $name_ar
 * @property int $position
 */
class ReportTypeSection extends Model
{
    protected $fillable = ['report_type_id', 'name_en', 'name_ar', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<ReportType, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    /** @return HasMany<ReportTypeField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ReportTypeField::class, 'section_id')->orderBy('position');
    }
}
