<?php

namespace App\Http\Controllers;

use App\Services\Backup\NativeBackupExporter;
use App\Services\Backup\NativeBackupExportResult;
use App\Support\ApplicationBackupManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    public function __construct(
        private readonly ApplicationBackupManager $backupManager,
        private readonly NativeBackupExporter $nativeBackupExporter,
    ) {}

    public function index(): View
    {
        return view('settings.backup');
    }

    public function downloadDatabase(): BinaryFileResponse|JsonResponse
    {
        if (config('nativephp-internal.running')) {
            return $this->nativeExportResponse(
                $this->nativeBackupExporter->exportFile(
                    sourcePath: $this->backupManager->currentDatabasePath(),
                    filename: $this->backupManager->databaseDownloadFilename(),
                    dialogTitle: 'Uložiť zálohu databázy',
                    filterName: 'SQLite záloha',
                    extensions: ['sqlite', 'db', 'sqlite3'],
                )
            );
        }

        return response()->download(
            $this->backupManager->currentDatabasePath(),
            $this->backupManager->databaseDownloadFilename(),
            ['Content-Type' => 'application/vnd.sqlite3']
        );
    }

    public function downloadData(): StreamedResponse|JsonResponse
    {
        $payload = $this->backupManager->exportData();

        if (config('nativephp-internal.running')) {
            return $this->nativeExportResponse(
                $this->nativeBackupExporter->exportContents(
                    contents: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                    filename: $this->backupManager->dataDownloadFilename(),
                    dialogTitle: 'Uložiť dátovú zálohu',
                    filterName: 'JSON záloha',
                    extensions: ['json'],
                )
            );
        }

        return response()->streamDownload(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }, $this->backupManager->dataDownloadFilename(), [
            'Content-Type' => 'application/json',
        ]);
    }

    public function restoreDatabase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'database_backup' => ['required', 'file'],
        ]);

        /** @var UploadedFile $databaseBackup */
        $databaseBackup = $validated['database_backup'];
        $databasePath = $databaseBackup->getRealPath();

        if ($databasePath === false) {
            return back()->withErrors([
                'database_backup' => 'Nahraný SQLite súbor sa nepodarilo prečítať.',
            ]);
        }

        try {
            $this->backupManager->restoreDatabaseFrom($databasePath);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors([
                'database_backup' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'SQLite záloha bola úspešne obnovená.');
    }

    public function restoreData(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'data_backup' => ['required', 'file'],
        ]);

        /** @var UploadedFile $dataBackup */
        $dataBackup = $validated['data_backup'];
        $dataPath = $dataBackup->getRealPath();

        if ($dataPath === false) {
            return back()->withErrors([
                'data_backup' => 'Nahraný JSON súbor sa nepodarilo prečítať.',
            ]);
        }

        try {
            $payload = json_decode(
                file_get_contents($dataPath) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (! is_array($payload)) {
                throw new InvalidArgumentException('JSON záloha má neplatnú štruktúru.');
            }

            $this->backupManager->restoreData($payload);
        } catch (InvalidArgumentException|JsonException $exception) {
            return back()->withErrors([
                'data_backup' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Dátová záloha bola úspešne obnovená.');
    }

    private function nativeExportResponse(NativeBackupExportResult $result): JsonResponse
    {
        return response()->json($result->toArray());
    }
}
