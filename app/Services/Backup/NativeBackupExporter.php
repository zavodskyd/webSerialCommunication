<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Native\Desktop\Dialog;
use RuntimeException;

class NativeBackupExporter
{
    public function __construct(
        private readonly Dialog $dialog,
    ) {}

    /**
     * @param  array<int, string>  $extensions
     */
    public function exportFile(
        string $sourcePath,
        string $filename,
        string $dialogTitle,
        string $filterName,
        array $extensions,
    ): NativeBackupExportResult {
        if (! File::isFile($sourcePath)) {
            throw new InvalidArgumentException('Zdrojový súbor zálohy neexistuje.');
        }

        $resolvedPath = $this->promptForSavePath($filename, $dialogTitle, $filterName, $extensions);

        if ($resolvedPath === null) {
            return new NativeBackupExportResult(cancelled: true);
        }

        if (! copy($sourcePath, $resolvedPath)) {
            throw new RuntimeException('Zálohu sa nepodarilo uložiť na vybrané miesto.');
        }

        return new NativeBackupExportResult(
            cancelled: false,
            path: $resolvedPath,
        );
    }

    /**
     * @param  array<int, string>  $extensions
     */
    public function exportContents(
        string $contents,
        string $filename,
        string $dialogTitle,
        string $filterName,
        array $extensions,
    ): NativeBackupExportResult {
        $resolvedPath = $this->promptForSavePath($filename, $dialogTitle, $filterName, $extensions);

        if ($resolvedPath === null) {
            return new NativeBackupExportResult(cancelled: true);
        }

        if (file_put_contents($resolvedPath, $contents) === false) {
            throw new RuntimeException('Zálohu sa nepodarilo uložiť na vybrané miesto.');
        }

        return new NativeBackupExportResult(
            cancelled: false,
            path: $resolvedPath,
        );
    }

    /**
     * @param  array<int, string>  $extensions
     */
    private function promptForSavePath(
        string $filename,
        string $dialogTitle,
        string $filterName,
        array $extensions,
    ): ?string {
        $savePath = $this->dialog
            ->title($dialogTitle)
            ->defaultPath($this->normalizeFilename($filename, $extensions[0] ?? null))
            ->button('Uložiť')
            ->filter($filterName, $extensions)
            ->save();

        if (! is_string($savePath) || trim($savePath) === '') {
            return null;
        }

        return $this->appendExtensionIfMissing($savePath, $extensions[0] ?? null);
    }

    private function normalizeFilename(string $filename, ?string $defaultExtension): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($filename)) ?? 'zaloga';

        return $this->appendExtensionIfMissing($normalized, $defaultExtension);
    }

    private function appendExtensionIfMissing(string $path, ?string $extension): string
    {
        if ($extension === null || $extension === '') {
            return $path;
        }

        $normalizedExtension = '.'.ltrim(strtolower($extension), '.');

        return str_ends_with(strtolower($path), $normalizedExtension)
            ? $path
            : $path.$normalizedExtension;
    }
}
