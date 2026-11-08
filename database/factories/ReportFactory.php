<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Database\Factories;

use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /** @var class-string<Report> */
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'report_type_id' => ReportType::factory(),
            'name' => fake()->words(3, true),
            'format' => ReportFormat::TABULAR,
            'definition' => ['v' => 1, 'columns' => ['l0.company_name']],
            'owner_id' => self::owner(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Report $report): void {
            $report->update(['code' => Report::codeFromId($report->id)]);
        });
    }

    /** The configured user model's factory when it has one (the app's users), else no owner. */
    private static function owner(): mixed
    {
        $model = Users::model();

        return method_exists($model, 'factory') ? $model::factory() : null;
    }
}
