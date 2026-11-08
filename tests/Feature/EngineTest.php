<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Feature;

use AhmedNour\CustomReports\Actions\CreateReportFolder;
use AhmedNour\CustomReports\Actions\CreateReportType;
use AhmedNour\CustomReports\Actions\DeployReportType;
use AhmedNour\CustomReports\Actions\SaveReportTypeLayout;
use AhmedNour\CustomReports\Actions\ShareReportFolder;
use AhmedNour\CustomReports\Actions\UpdateReportTypeChain;
use AhmedNour\CustomReports\Builder\Definition\DefinitionValidator;
use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Contracts\ReportVisibility;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\FolderAccess;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Enums\ReportFormat;
use AhmedNour\CustomReports\Enums\ReportScope;
use AhmedNour\CustomReports\Exceptions\InvalidReportDefinition;
use AhmedNour\CustomReports\Exceptions\ReportException;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Queries\ReportTypePicker;
use AhmedNour\CustomReports\Queries\RunCustomReport;
use AhmedNour\CustomReports\Queries\SummarizeCustomReport;
use AhmedNour\CustomReports\Support\ReportAccess;
use AhmedNour\CustomReports\Tests\Fixtures\Customer;
use AhmedNour\CustomReports\Tests\Fixtures\FixedAudience;
use AhmedNour\CustomReports\Tests\Fixtures\Order;
use AhmedNour\CustomReports\Tests\Fixtures\OwnerVisibility;
use AhmedNour\CustomReports\Tests\Fixtures\User;
use AhmedNour\CustomReports\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

/**
 * The engine on an app that isn't ECC: its own models and sources, its own
 * user model, and its own visibility, permissions and groups plugged in.
 */
final class EngineTest extends TestCase
{
    private User $manager;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.test']);
        $this->alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
        $this->bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.test']);

        $acme = Customer::query()->create(['code' => 'CUS-1', 'name' => 'Acme', 'region' => 'North', 'owner_id' => $this->alice->id]);
        $globex = Customer::query()->create(['code' => 'CUS-2', 'name' => 'Globex', 'region' => 'North', 'owner_id' => $this->bob->id]);
        Customer::query()->create(['code' => 'CUS-3', 'name' => 'Initech', 'region' => 'South', 'owner_id' => $this->bob->id]);

        Order::query()->create(['code' => 'ORD-1', 'customer_id' => $acme->id, 'total' => 100]);
        Order::query()->create(['code' => 'ORD-2', 'customer_id' => $acme->id, 'total' => 50]);
        Order::query()->create(['code' => 'ORD-3', 'customer_id' => $globex->id, 'total' => 30]);
    }

    public function test_a_report_type_is_built_and_runs_on_the_app_s_models(): void
    {
        $type = $this->customersWithOrders();

        $rows = $this->rows($type, $this->alice, ['l0.code', 'l1.code', 'l1.total']);

        // Default visibility: everyone sees everything.
        $this->assertSame([['CUS-1', 'ORD-1', 100.0], ['CUS-1', 'ORD-2', 50.0], ['CUS-2', 'ORD-3', 30.0]], $rows);
    }

    public function test_a_summary_with_a_formula(): void
    {
        $type = $this->customersWithOrders();
        $validated = app(DefinitionValidator::class)->validate($type, ReportDefinition::fromArray([
            'v' => 1,
            'groupings' => [['field' => 'l0.region']],
            'aggregates' => [['field' => 'l1.total', 'fn' => 'sum']],
            'formulas' => [['label' => 'Share', 'expression' => 'a0 / PARENTGROUPVAL(a0, GRAND_SUMMARY)', 'type' => 'percent']],
        ]), ReportFormat::SUMMARY);

        $result = app(SummarizeCustomReport::class)->handle($type, $validated, $this->manager);

        $this->assertEquals(180, $result['fact_map']['T!T']['aggregates']['a0']);
        $north = $result['groupings_down'][0];
        $this->assertSame('North', $north['value']);
        $this->assertEquals(1.0, $result['fact_map'][$north['key'].'!T']['formulas']['f0']);
    }

    public function test_the_app_s_visibility_narrows_every_report_and_enables_mine(): void
    {
        $type = $this->customersWithOrders();
        $this->assertSame([ReportScope::ALL], app(DefinitionValidator::class)->scopesOf($type));

        $this->app->bind(ReportVisibility::class, OwnerVisibility::class);

        $this->assertSame([['CUS-1', 'ORD-1'], ['CUS-1', 'ORD-2']], $this->rows($type, $this->alice, ['l0.code', 'l1.code']));
        $this->assertSame([['CUS-2', 'ORD-3']], $this->rows($type, $this->bob, ['l0.code', 'l1.code']));
        $this->assertCount(3, $this->rows($type, $this->manager, ['l0.code', 'l1.code']));

        // Customers have an owner now, so "mine" is offered — and narrows even for the manager.
        $this->assertSame(ReportScope::cases(), app(DefinitionValidator::class)->scopesOf($type));
        $this->assertSame([], $this->rows($type, $this->manager, ['l0.code'], ['scope' => 'mine']));
    }

    public function test_default_permissions_are_gate_abilities(): void
    {
        Gate::define('manage-report-types', fn (User $user): bool => $user->name === 'Manager');
        $this->customersWithOrders(deploy: false);

        $this->assertCount(1, app(ReportTypePicker::class)->handle($this->manager));
        $this->assertCount(0, app(ReportTypePicker::class)->handle($this->alice));
    }

    public function test_folders_are_shared_with_the_app_s_groups(): void
    {
        $this->app->instance(ReportAudience::class, new FixedAudience([$this->alice->id => [7]]));
        $folder = app(CreateReportFolder::class)->handle('Sales', $this->manager);
        app(ShareReportFolder::class)->handle($folder, [['group' => 7, 'access' => FolderAccess::VIEW]]);

        $access = app(ReportAccess::class);
        $this->assertSame('view', $access->forFolder($folder, $this->alice));
        $this->assertNull($access->forFolder($folder, $this->bob));
        $this->assertSame(ReportAccess::OWNER, $access->forFolder($folder, $this->manager));
    }

    public function test_failures_are_report_exceptions_with_a_slug(): void
    {
        $type = $this->customersWithOrders();

        try {
            app(DefinitionValidator::class)->validate($type, ReportDefinition::fromArray(['v' => 1, 'columns' => ['l0.nope']]), ReportFormat::TABULAR);
            $this->fail('accepted an unknown field');
        } catch (InvalidReportDefinition $e) {
            $this->assertInstanceOf(ReportException::class, $e);
            $this->assertSame(422, $e->status());
            $this->assertSame('report_definition_unknown_field', $e->errorCode());
        }
    }

    // ------------------------------------------------------------ helpers

    private function customersWithOrders(bool $deploy = true): ReportType
    {
        $type = app(CreateReportType::class)->handle(['name' => 'Customers with orders', 'primary_source' => 'customers'], $this->manager);
        app(UpdateReportTypeChain::class)->handle($type, 'customers', [
            ['source' => 'orders', 'parent_link' => 'orders.customer', 'join_type' => JoinType::INNER],
        ], $this->manager);
        app(SaveReportTypeLayout::class)->handle($type->refresh(), [[
            'name_en' => 'Fields',
            'fields' => array_map(static fn (string $key): array => ['key' => $key, 'is_default' => true], ['l0.code', 'l0.name', 'l0.region', 'l1.code', 'l1.total', 'l1.status']),
        ]], $this->manager);

        return $deploy ? app(DeployReportType::class)->handle($type->refresh(), $this->manager) : $type->refresh();
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $extra
     * @return list<list<mixed>>
     */
    private function rows(ReportType $type, User $viewer, array $columns, array $extra = []): array
    {
        $validated = app(DefinitionValidator::class)->validate($type, ReportDefinition::fromArray([
            'v' => 1, 'columns' => $columns, 'sort' => [['field' => 'l0.code', 'dir' => 'asc'], ['field' => $columns[1] ?? 'l0.code', 'dir' => 'asc']], ...$extra,
        ]), ReportFormat::TABULAR);

        $result = app(RunCustomReport::class)->handle($type, $validated, $viewer, 1, 50);

        return array_map(static fn (array $row): array => array_values(array_map(
            static fn (mixed $cell): mixed => is_array($cell) ? ($cell['code'] ?? null) : (is_numeric($cell) && ! is_string($cell) ? (float) $cell : (is_string($cell) && is_numeric($cell) ? (float) $cell : $cell)),
            $row,
        )), $result['rows']);
    }
}
