<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Database\Factories;

use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Models\ReportType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportType>
 */
class ReportTypeFactory extends Factory
{
    /** @var class-string<ReportType> */
    protected $model = ReportType::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->lexify('report_type_??????'),
            'name' => fake()->words(3, true),
            'category' => 'Sales',
            'status' => ReportTypeStatus::DEVELOPMENT,
            'primary_source' => 'accounts',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ReportType $type): void {
            $type->update(['code' => ReportType::codeFromId($type->id)]);
        });
    }

    public function deployed(): static
    {
        return $this->state(['status' => ReportTypeStatus::DEPLOYED, 'deployed_at' => now()]);
    }
}
