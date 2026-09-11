<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders Blade views to PDF using DomPDF.
 */
class PdfExportService
{
    private const LARGE_REPORT_MEMORY_LIMIT = '512M';

    private const LARGE_REPORT_TIME_LIMIT = 120;

    public function __construct(
        private string $paperSize = 'A4',
        private string $orientation = 'portrait',
    ) {}

    /**
     * Render a Blade view to a downloadable PDF response.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $filename): Response
    {
        $this->ensureMemoryLimit();

        return Pdf::loadView($view, $data)
            ->setPaper($this->paperSize, $this->orientation)
            ->download($filename);
    }

    /**
     * Render a Blade view to an inline (streamed) PDF response.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    public function stream(string $view, array $data, string $filename): Response
    {
        $this->ensureMemoryLimit();

        return Pdf::loadView($view, $data)
            ->setPaper($this->paperSize, $this->orientation)
            ->stream($filename);
    }

    private function ensureMemoryLimit(): void
    {
        // Web requests only. On the CLI the limit is unlimited by default, and
        // setting it here restarts the clock for the whole process: a test run
        // that renders a PDF would get 120 seconds for everything that follows
        // and die mid-suite with a fatal error that looks like a test failure.
        // Queue workers rendering large reports have the same problem.
        if (! app()->runningInConsole()) {
            set_time_limit(self::LARGE_REPORT_TIME_LIMIT);
        }

        $currentLimit = (string) ini_get('memory_limit');

        if ($currentLimit === '' || $currentLimit === '-1') {
            return;
        }

        $unit = strtolower(substr(trim($currentLimit), -1));
        $multiplier = match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        $currentBytes = (int) ((float) $currentLimit * $multiplier);

        if ($currentBytes < 512 * 1024 * 1024) {
            ini_set('memory_limit', self::LARGE_REPORT_MEMORY_LIMIT);
        }
    }
}
