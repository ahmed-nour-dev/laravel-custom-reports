<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests;

use AhmedNour\CustomReports\CustomReportsServiceProvider;
use AhmedNour\CustomReports\Tests\Fixtures\CustomersSource;
use AhmedNour\CustomReports\Tests\Fixtures\OrdersSource;
use AhmedNour\CustomReports\Tests\Fixtures\User;
use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Package tests run on MySQL / MariaDB (the engine uses their SQL), against
 * a tiny fixture app: users, customers, orders. Set DB_HOST, DB_PORT,
 * DB_DATABASE (default custom_reports_testing), DB_USERNAME and DB_PASSWORD.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CustomReportsServiceProvider::class, DomPdfServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing_mysql');
        $app['config']->set('database.connections.testing_mysql', [
            'driver' => getenv('DB_CONNECTION') ?: 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => getenv('DB_DATABASE') ?: 'custom_reports_testing',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
        $app['config']->set('custom-reports.sources', [CustomersSource::class, OrdersSource::class]);
        $app['config']->set('custom-reports.user_model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
