<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Actions;

use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Enums\ReportRunKind;
use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Exceptions\ReportRunUnavailable;
use AhmedNour\CustomReports\Jobs\RunCustomReportJob;
use AhmedNour\CustomReports\Models\Report;
use AhmedNour\CustomReports\Models\ReportRun;
use AhmedNour\CustomReports\Models\ReportSubscription;
use AhmedNour\CustomReports\Queries\SavedReportDefinition;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Queues a background run or export of a saved report for $viewer (SPEC
 * §14.4). A result is per viewer, never shared: an unfinished or unexpired
 * run of the same report, definition and kind for the same viewer is
 * returned instead of queuing another. The caller has checked the viewer
 * may open the report.
 *
 * A scheduled send passes its subscription: that always queues a fresh run
 * (the point is today's numbers), tagged so the viewer is emailed.
 */
final readonly class QueueReportRun
{
    public function __construct(private SavedReportDefinition $saved) {}

    public function handle(Report $report, Authenticatable $viewer, ReportRunKind $kind, ?ReportSubscription $subscription = null): ReportRun
    {
        [$type, $validated] = $this->saved->handle($report, $kind->isExport() ? ReportFormat::TABULAR : null);

        if (! $kind->isExport() && ! $validated->format->isGrouped()) {
            throw ReportRunUnavailable::notGrouped();
        }

        $definition = $validated->definition->toArray();
        $hash = hash('sha256', json_encode([$definition, $kind->value, $validated->format->value], JSON_THROW_ON_ERROR));

        $existing = ReportRun::query()
            ->where('report_id', $report->id)
            ->where('viewer_id', Users::id($viewer))
            ->where('kind', $kind->value)
            ->where('definition_hash', $hash)
            ->where(fn (Builder $usable) => $usable
                ->whereIn('status', [ReportRunStatus::QUEUED->value, ReportRunStatus::RUNNING->value])
                ->orWhere(fn (Builder $done) => $done->where('status', ReportRunStatus::DONE->value)->where('expires_at', '>', now())))
            ->latest('id')
            ->first();

        if ($existing instanceof ReportRun && ! $subscription instanceof ReportSubscription) {
            return $existing;
        }

        $run = DB::transaction(function () use ($report, $type, $viewer, $kind, $validated, $definition, $hash, $subscription): ReportRun {
            $run = ReportRun::query()->create([
                'report_id' => $report->id,
                'report_type_id' => $type->id,
                'report_subscription_id' => $subscription?->id,
                'viewer_id' => Users::id($viewer),
                'kind' => $kind,
                'format' => $validated->format,
                'definition' => $definition,
                'definition_hash' => $hash,
                'status' => ReportRunStatus::QUEUED,
            ]);
            $run->update(['code' => ReportRun::codeFromId($run->id)]);

            RunCustomReportJob::dispatch($run->id);

            return $run;
        });

        // A sync queue (tests, local) has already run it.
        return $run->refresh();
    }
}
