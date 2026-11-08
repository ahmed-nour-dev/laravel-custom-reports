<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/** A schedule that doesn't make sense. */
final class ReportSubscriptionInvalid extends ReportException
{
    private function __construct(string $message, private readonly string $slug)
    {
        parent::__construct($message);
    }

    public static function badDay(string $frequency): self
    {
        return new self(match ($frequency) {
            'weekly' => 'Pick a weekday (1 = Monday … 7 = Sunday).',
            'monthly' => 'Pick a day of the month from 1 to 28.',
            default => 'A daily schedule has no day.',
        }, 'report_subscription_bad_day');
    }

    public static function joined(): self
    {
        return new self('A joined report can\'t be scheduled yet.', 'report_subscription_joined');
    }

    public static function badTime(): self
    {
        return new self('The time must be HH:MM (24-hour).', 'report_subscription_bad_time');
    }

    public static function recipients(int $max): self
    {
        return new self("Pick from 1 to {$max} roles and users to send it to.", 'report_subscription_recipients');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return $this->slug;
    }
}
