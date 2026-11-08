<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Name, description, category and "auto-add new fields". The key and the
 * chain do not change here. Turning auto-add on records today's fields, so
 * only fields added afterwards are added; turning it off forgets them.
 */
final readonly class UpdateReportTypeBasics
{
    public function __construct(private AutoAddReportTypeFields $autoAdd) {}

    /**
     * @param  array{name: string, description?: string|null, category?: string|null, auto_add_fields?: bool}  $data
     */
    public function handle(ReportType $type, array $data, Authenticatable $actor): ReportType
    {
        $wasOn = $type->auto_add_fields;
        $on = (bool) ($data['auto_add_fields'] ?? $wasOn);

        $type->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'auto_add_fields' => $on,
            'known_fields' => $on ? $type->known_fields : null,
            'updated_by' => Users::id($actor),
        ]);

        if ($on && ! $wasOn) {
            $this->autoAdd->remember($type);
        }

        return $type;
    }
}
