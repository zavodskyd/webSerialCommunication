<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApplicationBackupManager
{
    private const JSON_BACKUP_FORMAT = 'serial-communication-backup-v1';

    /**
     * @var array<int, string>
     */
    private const APPLICATION_TABLES = [
        'users',
        'devices',
        'votings',
        'voting_questions',
        'voting_options',
        'voting_attendees',
        'votes',
        'vote_events',
    ];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_DATABASE_TABLES = [
        'migrations',
        'users',
        'devices',
        'votings',
    ];

    public function databaseDownloadFilename(): string
    {
        return 'backup-database-'.now()->format('Y-m-d').'.sqlite';
    }

    public function dataDownloadFilename(): string
    {
        return 'backup-data-'.now()->format('Y-m-d').'.json';
    }

    /**
     * @return array{
     *     format: string,
     *     exported_at: string,
     *     tables: array<string, array<int, array<string, mixed>>>
     * }
     */
    public function exportData(): array
    {
        $tables = [];

        foreach (self::APPLICATION_TABLES as $table) {
            $tables[$table] = DB::table($table)
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return [
            'format' => self::JSON_BACKUP_FORMAT,
            'exported_at' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    public function currentDatabasePath(): string
    {
        $defaultConnection = config('database.default');
        $databasePath = config("database.connections.{$defaultConnection}.database");

        if (! is_string($databasePath) || $databasePath === '' || $databasePath === ':memory:') {
            throw new InvalidArgumentException('Aktuálna databáza nie je dostupná ako SQLite súbor.');
        }

        return $databasePath;
    }

    public function restoreDatabaseFrom(string $sourcePath): void
    {
        $this->validateDatabaseBackup($sourcePath);

        $targetPath = $this->currentDatabasePath();

        DB::purge();

        if (! copy($sourcePath, $targetPath)) {
            throw new InvalidArgumentException('Zálohu databázy sa nepodarilo uložiť.');
        }

        clearstatcache(true, $targetPath);

        DB::purge();
        DB::reconnect();

        Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        DB::purge();
        DB::reconnect();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function restoreData(array $payload): void
    {
        $tables = $this->validatedJsonTables($payload);
        $deletionOrder = array_reverse(self::APPLICATION_TABLES);
        $foreignKeysWereEnabled = $this->foreignKeysEnabled();

        try {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::beginTransaction();

            foreach ($deletionOrder as $table) {
                DB::table($table)->delete();
            }

            foreach (self::APPLICATION_TABLES as $table) {
                $rows = $tables[$table];

                if ($rows === []) {
                    continue;
                }

                collect($rows)
                    ->chunk(250)
                    ->each(fn ($chunk): bool => DB::table($table)->insert($chunk->all()));
            }

            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        } finally {
            if ($foreignKeysWereEnabled) {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    public function validateDatabaseBackup(string $databasePath): void
    {
        $connectionName = 'backup_validation';
        $databaseConfig = config('database.connections.sqlite');

        if (! is_array($databaseConfig)) {
            throw new InvalidArgumentException('SQLite konfigurácia nie je dostupná.');
        }

        config([
            "database.connections.{$connectionName}" => [
                ...$databaseConfig,
                'database' => $databasePath,
            ],
        ]);

        try {
            $existingTables = DB::connection($connectionName)
                ->table('sqlite_master')
                ->where('type', 'table')
                ->pluck('name')
                ->all();

            $missingTables = array_values(array_diff(self::REQUIRED_DATABASE_TABLES, $existingTables));

            if ($missingTables !== []) {
                throw new InvalidArgumentException(
                    'SQLite záloha neobsahuje očakávané aplikačné tabuľky: '.implode(', ', $missingTables).'.'
                );
            }
        } finally {
            DB::purge($connectionName);
            config([
                "database.connections.{$connectionName}" => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function validatedJsonTables(array $payload): array
    {
        if (($payload['format'] ?? null) !== self::JSON_BACKUP_FORMAT) {
            throw new InvalidArgumentException('JSON záloha nemá podporovaný formát.');
        }

        $tables = $payload['tables'] ?? null;

        if (! is_array($tables)) {
            throw new InvalidArgumentException('JSON záloha neobsahuje sekciu tabuliek.');
        }

        $validatedTables = [];

        foreach (self::APPLICATION_TABLES as $table) {
            $rows = $tables[$table] ?? null;

            if (! is_array($rows)) {
                throw new InvalidArgumentException("JSON záloha neobsahuje tabuľku {$table}.");
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException("JSON záloha obsahuje neplatný riadok v tabuľke {$table}.");
                }
            }

            $validatedTables[$table] = $rows;
        }

        return $validatedTables;
    }

    private function foreignKeysEnabled(): bool
    {
        $result = DB::selectOne('PRAGMA foreign_keys');

        return (bool) ($result->foreign_keys ?? 0);
    }
}
