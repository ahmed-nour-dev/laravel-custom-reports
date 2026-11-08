<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/** The user can see a report or folder but not change it. */
final class ReportAccessDenied extends ReportException
{
    private function __construct(string $message, private readonly string $slug)
    {
        parent::__construct($message);
    }

    public static function readOnly(string $code): self
    {
        return new self("{$code} is shared with you read-only.", 'report_read_only');
    }

    public static function notOwner(string $code): self
    {
        return new self("Only the owner can change {$code}.", 'report_folder_not_owner');
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return $this->slug;
    }
}
