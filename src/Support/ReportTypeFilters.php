<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support;

use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * "Which report type has what I need": filters shared by the admin list and
 * the new-report picker.
 *
 * - `object`: a registry source key, matched as the primary object or any
 *   added object.
 * - `field`: free text, matched against a layout field's registry name
 *   ("retail price" finds target_retail_price) or its label override.
 */
final readonly class ReportTypeFilters
{
    /**
     * @param  Builder<ReportType>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<ReportType>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $object = self::text($filters['object'] ?? null);
        $field = self::text($filters['field'] ?? null);

        return $query
            ->when($object, fn (Builder $q, string $source) => $q->where(fn (Builder $match) => $match
                ->where('primary_source', $source)
                ->orWhereHas('objects', fn (Builder $objects) => $objects->where('source', $source))))
            ->when($field, fn (Builder $q, string $text) => $q->whereHas('fields', function (Builder $fields) use ($text): void {
                $like = '%'.addcslashes($text, '%_\\').'%';
                $name = '%'.addcslashes(Str::snake($text), '%_\\').'%';
                $fields->where(fn (Builder $match) => $match
                    ->where('field', 'like', $name)
                    ->orWhere('label_en', 'like', $like)
                    ->orWhere('label_ar', 'like', $like));
            }));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }
}
