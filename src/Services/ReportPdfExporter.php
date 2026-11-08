<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Services;

use AhmedNour\CustomReports\Data\ExportResult;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;

/**
 * Renders an ExportResult as a downloadable PDF.
 */
final class ReportPdfExporter
{
    /**
     * Generate a PDF response from the given export result.
     *
     * @param  string  $filename  The download filename (without extension).
     */
    public function download(ExportResult $result, string $filename = 'report'): Response
    {
        return $this->pdf($result)->download($filename.'.pdf');
    }

    /** The PDF's bytes, for a background export written to a file. */
    public function output(ExportResult $result): string
    {
        return $this->pdf($result)->output();
    }

    private function pdf(ExportResult $result): DomPdf
    {
        return Pdf::loadView('custom-reports::export-pdf', [
            'title' => $result->title,
            'columns' => $result->columns,
            'rows' => $result->rows,
            'appliedFilters' => $result->appliedFilters,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ])->setPaper('a4', 'landscape');
    }
}
