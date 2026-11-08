<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports;

use AhmedNour\CustomReports\Builder\Registry\ReportableSource;
use AhmedNour\CustomReports\Builder\Registry\SourceRegistry;
use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Contracts\ReportPermissions;
use AhmedNour\CustomReports\Contracts\ReportVisibility;
use AhmedNour\CustomReports\Contracts\RunsSavedReport;
use AhmedNour\CustomReports\Support\DashboardCharts;
use AhmedNour\CustomReports\Support\Defaults\GatePermissions;
use AhmedNour\CustomReports\Support\Defaults\ListedUsersAudience;
use AhmedNour\CustomReports\Support\Defaults\OpenVisibility;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the custom report engine: its config, the source registry built
 * from `custom-reports.sources`, permissive defaults for the contracts the
 * app plugs in (bound with bindIf, so the app's own bindings win), its
 * migrations and its PDF view.
 */
final class CustomReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/custom-reports.php', 'custom-reports');

        // Scoped: one registry per request (or job), rebuilt on the next. The
        // sources are read when it is first resolved, so config set after
        // register (tests, app providers) still counts.
        $this->app->scoped(SourceRegistry::class, static function (Application $app): SourceRegistry {
            /** @var list<class-string<ReportableSource>> $classes */
            $classes = (array) config('custom-reports.sources', []);

            return new SourceRegistry(array_map(static fn (string $class): ReportableSource => $app->make($class), $classes));
        });

        $this->app->bindIf(ReportVisibility::class, OpenVisibility::class);
        $this->app->bindIf(ReportPermissions::class, GatePermissions::class);
        $this->app->bindIf(ReportAudience::class, ListedUsersAudience::class);
        $this->app->bindIf(RunsSavedReport::class, DashboardCharts::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'custom-reports');

        if ((bool) config('custom-reports.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/custom-reports.php' => config_path('custom-reports.php')], 'custom-reports-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'custom-reports-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/custom-reports')], 'custom-reports-views');
        }
    }
}
