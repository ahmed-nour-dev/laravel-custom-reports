<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Database\Factories;

use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Models\ReportTypeObject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTypeObject>
 */
class ReportTypeObjectFactory extends Factory
{
    /** @var class-string<ReportTypeObject> */
    protected $model = ReportTypeObject::class;

    public function definition(): array
    {
        return [
            'report_type_id' => ReportType::factory(),
            'level' => 1,
            'source' => 'samples',
            'parent_link' => 'samples.account',
            'join_type' => JoinType::INNER,
        ];
    }
}
