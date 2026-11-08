<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** What a background run produces (SPEC §14.4). */
enum ReportRunKind: string
{
    /** A summary or matrix result, opened on the run's page. */
    case RUN = 'run';
    case EXPORT_CSV = 'export_csv';
    case EXPORT_PDF = 'export_pdf';

    public function isExport(): bool
    {
        return $this !== self::RUN;
    }

    /** The result file's extension. */
    public function extension(): string
    {
        return match ($this) {
            self::RUN => 'json',
            self::EXPORT_CSV => 'csv',
            self::EXPORT_PDF => 'pdf',
        };
    }
}
