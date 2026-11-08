<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field in a report type's layout. `key` is the stable reference saved
 * reports use: `l1.total`, `l1@account_manager.name`, `l0@lead.lead_source.name`.
 *
 * @property int $id
 * @property int $report_type_id
 * @property string $key
 * @property int $object_level
 * @property string|null $lookup_path
 * @property string $field
 * @property string|null $label_en
 * @property string|null $label_ar
 * @property int|null $section_id
 * @property int $position
 * @property bool $is_default
 * @property bool $is_repeated
 */
class ReportTypeField extends Model
{
    protected $fillable = [
        'report_type_id', 'key', 'object_level', 'lookup_path', 'field', 'label_en', 'label_ar',
        'section_id', 'position', 'is_default', 'is_repeated',
    ];

    protected $casts = [
        'object_level' => 'integer',
        'position' => 'integer',
        'is_default' => 'boolean',
        'is_repeated' => 'boolean',
    ];

    /** @return BelongsTo<ReportType, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    /** @return BelongsTo<ReportTypeSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ReportTypeSection::class, 'section_id');
    }
}
