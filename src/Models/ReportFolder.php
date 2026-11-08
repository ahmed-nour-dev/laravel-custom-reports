<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Models;

use AhmedNour\CustomReports\Database\Factories\ReportFolderFactory;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of saved reports, shared per role with view or edit access
 * (SPEC §8.4). Sharing a folder shares the definitions, not the rows: every
 * run uses the runner's own visibility.
 *
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property int|null $owner_id
 */
#[UseFactory(ReportFolderFactory::class)]
class ReportFolder extends Model
{
    /** @use HasFactory<ReportFolderFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'owner_id'];

    public static function codeFromId(int $id): string
    {
        return 'RFD-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Model, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::model(), 'owner_id');
    }

    /** @return HasMany<ReportFolderShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(ReportFolderShare::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
