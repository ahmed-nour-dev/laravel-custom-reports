<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

use RuntimeException;

/**
 * Base class of every failure the report engine raises on purpose. An app
 * renders it as an API error: `{ "message", "error": errorCode(), "context" }`
 * with status() as the HTTP status.
 */
abstract class ReportException extends RuntimeException
{
    /** The HTTP status it maps to. */
    abstract public function status(): int;

    /** A stable snake_case slug the client can branch on. */
    abstract public function errorCode(): string;

    /**
     * Structured detail for the client.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
