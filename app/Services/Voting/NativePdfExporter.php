<?php

declare(strict_types=1);

namespace App\Services\Voting;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Desktop\Client\Client;
use Native\Desktop\Dialog;
use RuntimeException;

class NativePdfExporter
{
    public function __construct(
        private readonly Dialog $dialog,
        private readonly Client $client,
    ) {}

    public function export(string $html, string $filename): NativePdfExportResult
    {
        Log::info('NativePdfExporter: opening save dialog', [
            'filename' => $filename,
            'html_length' => strlen($html),
        ]);

        $savePath = $this->dialog
            ->title('Uložiť PDF')
            ->defaultPath($this->normalizeFilename($filename))
            ->button('Uložiť')
            ->filter('Dokument vo formáte PDF', ['pdf'])
            ->save();

        if (! is_string($savePath) || trim($savePath) === '') {
            Log::info('NativePdfExporter: export cancelled in save dialog', [
                'filename' => $filename,
            ]);

            return new NativePdfExportResult(cancelled: true);
        }

        $resolvedPath = str_ends_with(strtolower($savePath), '.pdf')
            ? $savePath
            : $savePath.'.pdf';

        Log::info('NativePdfExporter: save path selected', [
            'save_path' => $savePath,
            'resolved_path' => $resolvedPath,
        ]);

        $temporaryHtmlPath = $this->createTemporaryHtmlFile($html);

        Log::info('NativePdfExporter: temporary html file created', [
            'temporary_html_path' => $temporaryHtmlPath,
        ]);

        try {
            $response = $this->client->post('system/print-to-pdf', [
                'htmlPath' => $temporaryHtmlPath,
                'settings' => [
                    'landscape' => true,
                    'pageSize' => 'A4',
                    'printBackground' => true,
                ],
            ]);

            if (! $response->successful()) {
                $errorMessage = $response->json('error')
                    ?? $response->json('message')
                    ?? $response->body();

                Log::error('NativePdfExporter: NativePHP print-to-pdf request failed', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                throw new RuntimeException(
                    'NativePHP PDF export zlyhal: '.trim((string) $errorMessage)
                );
            }

            $pdf = $response->json('result');

            if (! is_string($pdf) || $pdf === '') {
                Log::error('NativePdfExporter: NativePHP returned empty pdf payload', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException('NativePHP vrátil prázdne PDF dáta.');
            }

            Log::info('NativePdfExporter: printToPDF returned payload', [
                'payload_length' => strlen($pdf),
            ]);
        } finally {
            File::delete($temporaryHtmlPath);
        }

        $binary = base64_decode($pdf, true);

        if ($binary === false) {
            Log::error('NativePdfExporter: invalid base64 payload from NativePHP');

            throw new InvalidArgumentException('NativePHP vrátil neplatné PDF dáta.');
        }

        Log::info('NativePdfExporter: decoded pdf payload', [
            'binary_length' => strlen($binary),
        ]);

        if (file_put_contents($resolvedPath, $binary) === false) {
            Log::error('NativePdfExporter: failed to write pdf to disk', [
                'resolved_path' => $resolvedPath,
            ]);

            throw new RuntimeException('PDF sa nepodarilo uložiť na vybrané miesto.');
        }

        Log::info('NativePdfExporter: pdf written successfully', [
            'resolved_path' => $resolvedPath,
        ]);

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

    private function createTemporaryHtmlFile(string $html): string
    {
        $directory = storage_path('framework/native-pdf-exports');

        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.Str::uuid().'.html';

        File::put($path, $html);

        return $path;
    }
}
