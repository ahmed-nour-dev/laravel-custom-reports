<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Database\Factories\ReportTypeObjectFactory;
use AhmedNour\CustomReports\Enums\JoinType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child level of a report type's chain: which source, through which
 * registry child link, and whether the parent must have one (inner) or may
 * not (left).
 *
 * @property int $id
 * @property int $report_type_id
 * @property int $level
 * @property string $source
 * @property string $parent_link
 * @property JoinType $join_type
 */
class ReportTypeObject extends Model
{
    /** @use HasFactory<ReportTypeObjectFactory> */
    use HasFactory;

    /** Package models live outside the app namespace, so name the factory explicitly. */
    protected static function newFactory(): ReportTypeObjectFactory
    {
        return ReportTypeObjectFactory::new();
    }

    protected $fillable = ['report_type_id', 'level', 'source', 'parent_link', 'join_type'];

    protected $casts = [
        'level' => 'integer',
        'join_type' => JoinType::class,
    ];

    /** @return BelongsTo<ReportType, $this> */
    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }
}
