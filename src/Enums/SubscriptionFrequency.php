<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a scheduled report goes out (SPEC §14.5), at a time of day in the
 * app's timezone: daily; weekly on a weekday (1 = Monday … 7 = Sunday);
 * monthly on a day from 1 to 28, so every month has it.
 */
enum SubscriptionFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    /**
     * The valid `day` values, or null when the frequency takes none.
     *
     * @return list<int>|null
     */
    public function days(): ?array
    {
        return match ($this) {
            self::DAILY => null,
            self::WEEKLY => range(1, 7),
            self::MONTHLY => range(1, 28),
        };
    }

    /** The first send strictly after $after. */
    public function nextAfter(CarbonImmutable $after, ?int $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));
        $at = static fn (CarbonImmutable $date): CarbonImmutable => $date->setTime($hour, $minute);

        $candidate = match ($this) {
            self::DAILY => $at($after),
            self::WEEKLY => $at($after->addDays((((int) $day) - $after->isoWeekday() + 7) % 7)),
            self::MONTHLY => $at($after->setDay((int) $day)),
        };

        while ($candidate <= $after) {
            $candidate = match ($this) {
                self::DAILY => $candidate->addDay(),
                self::WEEKLY => $candidate->addWeek(),
                self::MONTHLY => $candidate->addMonthNoOverflow(),
            };
        }

        return $candidate;
    }
}
