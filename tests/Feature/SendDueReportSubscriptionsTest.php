<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Feature;

use AhmedNour\CustomReports\Actions\CreateReportType;
use AhmedNour\CustomReports\Actions\DeployReportType;
use AhmedNour\CustomReports\Actions\SaveReport;
use AhmedNour\CustomReports\Actions\SaveReportSubscription;
use AhmedNour\CustomReports\Actions\SaveReportTypeLayout;
use AhmedNour\CustomReports\Actions\SendDueReportSubscriptions;
use AhmedNour\CustomReports\Actions\UpdateReportTypeChain;
use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Data\ReportDefinition;
use AhmedNour\CustomReports\Enums\JoinType;
use AhmedNour\CustomReports\Enums\SubscriptionFrequency;
use AhmedNour\CustomReports\Jobs\RunCustomReportJob;
use AhmedNour\CustomReports\Models\ReportRun;
use AhmedNour\CustomReports\Models\ReportSubscription;
use AhmedNour\CustomReports\Models\ReportType;
use AhmedNour\CustomReports\Tests\Fixtures\User;
use AhmedNour\CustomReports\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;

/**
 * Scheduled sends go out at most once per occurrence: overlapping
 * schedulers don't double up, and a failure partway through doesn't make
 * the next tick send it again.
 */
final class SendDueReportSubscriptionsTest extends TestCase
{
    private User $manager;

    private User $alice;

    private User $bob;

    private ReportType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Gate::define('manage-report-types', fn (): bool => true);

        $this->manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.test']);
        $this->alice = User::query()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
        $this->bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.test']);
        $this->type = $this->customersWithOrders();
    }

    public function test_a_due_subscription_queues_a_run_per_recipient_and_moves_on(): void
    {
        $subscription = $this->dueSubscription('By region', [$this->alice->id, $this->bob->id]);

        $this->assertSame(2, app(SendDueReportSubscriptions::class)->handle());

        Queue::assertPushed(RunCustomReportJob::class, 2);
        $this->assertSame(2, ReportRun::query()->where('report_subscription_id', $subscription->id)->count());

        $subscription->refresh();
        $this->assertNotNull($subscription->last_run_at);
        $this->assertTrue($subscription->next_run_at?->isFuture());

        // Not due any more.
        $this->assertSame(0, app(SendDueReportSubscriptions::class)->handle());
        Queue::assertPushed(RunCustomReportJob::class, 2);
    }

    public function test_overlapping_schedulers_send_an_occurrence_once(): void
    {
        $subscription = $this->dueSubscription('By region', [$this->alice->id, $this->bob->id]);

        // As the first scheduler reads the due row, a second one sends it.
        $overlapped = false;
        $second = null;
        ReportSubscription::retrieved(function () use (&$overlapped, &$second): void {
            if (! $overlapped) {
                $overlapped = true;
                $second = app(SendDueReportSubscriptions::class)->handle();
            }
        });

        $first = app(SendDueReportSubscriptions::class)->handle();

        $this->assertSame(2, $second);
        $this->assertSame(0, $first);
        Queue::assertPushed(RunCustomReportJob::class, 2);
        $this->assertSame(2, ReportRun::query()->where('report_subscription_id', $subscription->id)->count());
    }

    public function test_an_unexpected_error_is_reported_without_a_resend_or_blocking_the_rest(): void
    {
        // A spy rather than Exceptions::fake(), which needs Laravel 11+.
        $handler = $this->spy(ExceptionHandler::class);
        $this->app->instance(ReportAudience::class, $this->audienceFailingAt($this->bob));

        // Alice is queued, then the app's audience throws on Bob.
        $broken = $this->dueSubscription('Broken', [$this->alice->id, $this->bob->id]);
        $later = $this->dueSubscription('Later', [$this->alice->id]);

        $this->assertSame(2, app(SendDueReportSubscriptions::class)->handle());

        $handler->shouldHaveReceived('report')->with(Mockery::type(RuntimeException::class))->atLeast()->once();
        $this->assertSame(1, ReportRun::query()->where('report_subscription_id', $broken->id)->count());
        $this->assertSame(1, ReportRun::query()->where('report_subscription_id', $later->id)->count());
        $this->assertTrue($broken->refresh()->next_run_at?->isFuture());

        // The next tick doesn't send Alice the broken one again.
        $this->assertSame(0, app(SendDueReportSubscriptions::class)->handle());
        Queue::assertPushed(RunCustomReportJob::class, 2);
    }

    // ------------------------------------------------------------ helpers

    /**
     * A summary report on the type, scheduled daily and already due.
     *
     * @param  list<int>  $userIds
     */
    private function dueSubscription(string $name, array $userIds): ReportSubscription
    {
        $report = app(SaveReport::class)->handle($this->type, ReportDefinition::fromArray([
            'v' => 1,
            'groupings' => [['field' => 'l0.region']],
            'aggregates' => [['field' => 'l1.total', 'fn' => 'sum']],
        ]), ['name' => $name], null, $this->manager);

        $subscription = app(SaveReportSubscription::class)->handle($report, SubscriptionFrequency::DAILY, null, '08:00', [], $userIds, $this->manager);
        $subscription->update(['next_run_at' => now()->subMinute()]);

        return $subscription->refresh();
    }

    /** The listed users, in order, throwing when it reaches $failing. */
    private function audienceFailingAt(User $failing): ReportAudience
    {
        return new readonly class($failing->id) implements ReportAudience
        {
            public function __construct(private int $failingId) {}

            public function groupIdsOf(Authenticatable $user): array
            {
                return [];
            }

            public function recipients(array $groupIds, array $userIds): iterable
            {
                foreach ($userIds as $id) {
                    if ($id === $this->failingId) {
                        throw new RuntimeException('Audience lookup failed.');
                    }

                    yield User::query()->findOrFail($id);
                }
            }
        };
    }

    private function customersWithOrders(): ReportType
    {
        $type = app(CreateReportType::class)->handle(['name' => 'Customers with orders', 'primary_source' => 'customers'], $this->manager);
        app(UpdateReportTypeChain::class)->handle($type, 'customers', [
            ['source' => 'orders', 'parent_link' => 'orders.customer', 'join_type' => JoinType::INNER],
        ], $this->manager);
        app(SaveReportTypeLayout::class)->handle($type->refresh(), [[
            'name_en' => 'Fields',
            'fields' => array_map(static fn (string $key): array => ['key' => $key, 'is_default' => true], ['l0.code', 'l0.region', 'l1.code', 'l1.total']),
        ]], $this->manager);

        return app(DeployReportType::class)->handle($type->refresh(), $this->manager);
    }
}
