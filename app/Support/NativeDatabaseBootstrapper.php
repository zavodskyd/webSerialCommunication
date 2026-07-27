<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NativeDatabaseBootstrapper
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function runPendingMigrations(): bool
    {
        if (! config('nativephp-internal.running')) {
            return false;
        }

        $exitCode = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Native database migration failed with exit code {$exitCode}.");
        }

        return true;
    }

    public function hasDatabaseSchema(): bool
    {
        if (! config('nativephp-internal.running')) {
            return false;
        }

        $connection = DB::connection();

        return $connection
            ->table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'migrations')
            ->exists();
    }

    public function backupBeforeMigrations(
        string $version,
        ?string $destinationDirectory = null,
    ): ?string {
        if (! config('nativephp-internal.running')) {
            return null;
        }

        $connection = DB::connection();
        $databasePath = $this->sqliteDatabasePath($connection);

        if ($databasePath === null || ! $this->files->isFile($databasePath)) {
            throw new RuntimeException('Native database backup failed because the SQLite database file was not found.');
        }

        $destinationDirectory ??= storage_path('app/database-backups');
        $this->files->ensureDirectoryExists($destinationDirectory);

        $safeVersion = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $version), '-');
        $safeVersion = $safeVersion !== '' ? $safeVersion : 'unknown-version';
        $backupPath = $destinationDirectory.DIRECTORY_SEPARATOR.sprintf(
            'pre-migration-%s-%s-%s.sqlite',
            $safeVersion,
            now()->format('Ymd-His'),
            Str::lower(Str::random(8)),
        );

        $connection->statement(
            "VACUUM INTO '{$this->escapeSqlitePath($backupPath)}'"
        );

        if (! $this->files->isFile($backupPath) || $this->files->size($backupPath) === 0) {
            throw new RuntimeException('Native database backup failed because the backup file was not created.');
        }

        return $backupPath;
    }

    private function sqliteDatabasePath(ConnectionInterface $connection): ?string
    {
        $databasePath = $connection->getConfig('database');

        if (! is_string($databasePath) || $databasePath === '' || $databasePath === ':memory:') {
            return null;
        }

        if ($this->isAbsolutePath($databasePath)) {
            return $databasePath;
        }

        return base_path($databasePath);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function escapeSqlitePath(string $path): string
    {
        return str_replace("'", "''", $path);
    }
}
