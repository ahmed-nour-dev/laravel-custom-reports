<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Unit\Delivery;

use AhmedNour\CustomReports\Enums\SubscriptionFrequency;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** When a scheduled report goes out next: always strictly after the given moment. */
final class SubscriptionFrequencyTest extends TestCase
{
    /**
     * @return iterable<string, array{SubscriptionFrequency, ?int, string, string, string}>
     */
    public static function cases(): iterable
    {
        // 2026-11-11 is a Wednesday.
        yield 'daily, later today' => [SubscriptionFrequency::DAILY, null, '18:00', '2026-11-11 09:00', '2026-11-11 18:00'];
        yield 'daily, already passed' => [SubscriptionFrequency::DAILY, null, '08:00', '2026-11-11 09:00', '2026-11-12 08:00'];
        yield 'daily, exactly now' => [SubscriptionFrequency::DAILY, null, '09:00', '2026-11-11 09:00', '2026-11-12 09:00'];
        yield 'weekly, Friday' => [SubscriptionFrequency::WEEKLY, 5, '08:00', '2026-11-11 09:00', '2026-11-13 08:00'];
        yield 'weekly, Monday next week' => [SubscriptionFrequency::WEEKLY, 1, '08:00', '2026-11-11 09:00', '2026-11-16 08:00'];
        yield 'weekly, today but passed' => [SubscriptionFrequency::WEEKLY, 3, '08:00', '2026-11-11 09:00', '2026-11-18 08:00'];
        yield 'weekly, today and still ahead' => [SubscriptionFrequency::WEEKLY, 3, '10:00', '2026-11-11 09:00', '2026-11-11 10:00'];
        yield 'monthly, later this month' => [SubscriptionFrequency::MONTHLY, 20, '07:30', '2026-11-11 09:00', '2026-11-20 07:30'];
        yield 'monthly, next month' => [SubscriptionFrequency::MONTHLY, 1, '07:30', '2026-11-11 09:00', '2026-12-01 07:30'];
        yield 'monthly, into the new year' => [SubscriptionFrequency::MONTHLY, 5, '07:30', '2026-12-28 09:00', '2027-01-05 07:30'];
    }

    #[DataProvider('cases')]
    public function test_next_send(SubscriptionFrequency $frequency, ?int $day, string $time, string $after, string $expected): void
    {
        $this->assertSame($expected.':00', $frequency->nextAfter(CarbonImmutable::parse($after), $day, $time)->toDateTimeString());
    }

    public function test_the_days_each_frequency_takes(): void
    {
        $this->assertNull(SubscriptionFrequency::DAILY->days());
        $this->assertSame(range(1, 7), SubscriptionFrequency::WEEKLY->days());
        $this->assertSame(range(1, 28), SubscriptionFrequency::MONTHLY->days());
    }
}
