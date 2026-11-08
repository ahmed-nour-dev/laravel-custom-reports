<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/** A background run that can't be queued, or whose result can't be had (yet, or any more). */
final class ReportRunUnavailable extends ReportException
{
    private function __construct(string $message, private readonly string $slug, private readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    /** Only summaries and matrices run in the background; a table exports instead. */
    public static function notGrouped(): self
    {
        return new self('Only summary and matrix reports run in the background. Export a table instead.', 'report_run_not_grouped', 422);
    }

    public static function notReady(string $code): self
    {
        return new self("{$code} isn't finished.", 'report_run_not_ready', 409);
    }

    public static function expired(string $code): self
    {
        return new self("{$code} has expired. Run the report again.", 'report_run_expired', 410);
    }

    /** Its rows belong to the viewer who ran it; a report-type manager sees the status only. */
    public static function notTheViewer(string $code): self
    {
        return new self("Only the person who ran {$code} can open its result.", 'report_run_not_viewer', 403);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->slug;
    }
}
