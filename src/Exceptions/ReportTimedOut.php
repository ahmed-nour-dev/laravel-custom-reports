<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/** A report run hit the MAX_EXECUTION_TIME guardrail (SPEC §6.4). */
final class ReportTimedOut extends ReportException
{
    public static function after(int $milliseconds): self
    {
        return new self("The report took longer than {$milliseconds} ms. Add filters to narrow it down.");
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'report_timed_out';
    }
}
