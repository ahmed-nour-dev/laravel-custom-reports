<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Database\Factories;

use AhmedNour\CustomReports\Models\ReportFolder;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportFolder>
 */
class ReportFolderFactory extends Factory
{
    /** @var class-string<ReportFolder> */
    protected $model = ReportFolder::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'owner_id' => self::owner(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ReportFolder $folder): void {
            $folder->update(['code' => ReportFolder::codeFromId($folder->id)]);
        });
    }

    /** The configured user model's factory when it has one (the app's users), else no owner. */
    private static function owner(): mixed
    {
        $model = Users::model();

        return method_exists($model, 'factory') ? $model::factory() : null;
    }
}
