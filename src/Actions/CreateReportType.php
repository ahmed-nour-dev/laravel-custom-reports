<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Starts a report type in development: the basics and the primary object, no chain yet. */
final readonly class CreateReportType
{
    public function __construct(
        private SourceRegistry $registry,
        private AutoAddReportTypeFields $autoAdd,
    ) {}

    /**
     * @param  array{name: string, primary_source: string, description?: string|null, category?: string|null, auto_add_fields?: bool}  $data
     */
    public function handle(array $data, Authenticatable $actor): ReportType
    {
        $this->registry->source($data['primary_source']);

        return DB::transaction(function () use ($data, $actor): ReportType {
            $type = ReportType::query()->create([
                'key' => $this->uniqueKey($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'status' => ReportTypeStatus::DEVELOPMENT,
                'primary_source' => $data['primary_source'],
                'auto_add_fields' => (bool) ($data['auto_add_fields'] ?? false),
                'created_by' => Users::id($actor),
                'updated_by' => Users::id($actor),
            ]);

            $type->update(['code' => ReportType::codeFromId($type->id)]);

            if ($type->auto_add_fields) {
                $this->autoAdd->remember($type);
            }

            return $type;
        });
    }

    /** A stable slug from the name; numbered when taken (deleted types included). */
    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 60, '') ?: 'report_type';
        $key = $base;

        for ($n = 2; ReportType::withTrashed()->where('key', $key)->exists(); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }
}
