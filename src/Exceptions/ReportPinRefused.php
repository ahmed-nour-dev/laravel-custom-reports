<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/** A report that can't go on the dashboard. */
final class ReportPinRefused extends ReportException
{
    private function __construct(string $message, private readonly string $slug)
    {
        parent::__construct($message);
    }

    public static function noChart(string $code): self
    {
        return new self("{$code} has no chart to show on the dashboard. Add one in the builder first.", 'report_pin_no_chart');
    }

    public static function tooMany(int $max): self
    {
        return new self("At most {$max} report charts fit on the dashboard. Unpin one first.", 'report_pin_too_many');
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
