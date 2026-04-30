<?php

declare(strict_types=1);

namespace App\Services\Voting;

use InvalidArgumentException;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\System;
use RuntimeException;

class NativePdfExporter
{
    public function __construct(private readonly Dialog $dialog) {}

    public function export(string $html, string $filename): NativePdfExportResult
    {
        $savePath = $this->dialog
            ->title('Uložiť PDF')
            ->defaultPath($this->normalizeFilename($filename))
            ->button('Uložiť')
            ->filter('PDF', ['pdf'])
            ->save();

        if (! is_string($savePath) || trim($savePath) === '') {
            return new NativePdfExportResult(cancelled: true);
        }

        $resolvedPath = str_ends_with(strtolower($savePath), '.pdf')
            ? $savePath
            : $savePath.'.pdf';

        $pdf = System::printToPDF($html, [
            'landscape' => true,
            'pageSize' => 'A4',
            'printBackground' => true,
        ]);

        $binary = base64_decode($pdf, true);

        if ($binary === false) {
            throw new InvalidArgumentException('NativePHP vrátil neplatné PDF dáta.');
        }

        if (file_put_contents($resolvedPath, $binary) === false) {
            throw new RuntimeException('PDF sa nepodarilo uložiť na vybrané miesto.');
        }

        return new NativePdfExportResult(
            cancelled: false,
            path: $resolvedPath,
        );
    }

    private function normalizeFilename(string $filename): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($filename)) ?? 'Hlasovanie.pdf';

        return str_ends_with(strtolower($normalized), '.pdf')
            ? $normalized
            : $normalized.'.pdf';
    }
}
