<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Builder\ReportTypeValidator;
use AhmedNour\CustomReports\Enums\ReportTypeStatus;
use AhmedNour\CustomReports\Exceptions\InvalidReportType;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/** Makes a report type available to report builders, after the SPEC §11 checks pass. */
final readonly class DeployReportType
{
    public function __construct(private ReportTypeValidator $validator) {}

    public function handle(ReportType $type, Authenticatable $actor): ReportType
    {
        $problems = $this->validator->deployProblems($type);

        if ($problems !== []) {
            throw InvalidReportType::notDeployable($problems);
        }

        $type->update([
            'status' => ReportTypeStatus::DEPLOYED,
            'deployed_at' => now(),
            'updated_by' => Users::id($actor),
        ]);

        return $type;
    }
}
