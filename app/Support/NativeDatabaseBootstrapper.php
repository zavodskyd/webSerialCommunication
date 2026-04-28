<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class NativeDatabaseBootstrapper
{
    public const BUNDLED_SEED_DATABASE = 'nativephp-seed.sqlite';

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
    ];

    /**
     * Apply pending Laravel migrations on the user's local SQLite. MUST run on
     * every NativePHP boot — not only on first launch — because a returning
     * user's DB already contains data from a prior build, so seedFromBundled…
     * skips it. Without this, schema additions (e.g. vote_events) are missing
     * after an update and queries blow up with 500s.
     */
    public function runPendingMigrations(): bool
    {
        if (! config('nativephp-internal.running')) {
            return false;
        }

        Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return true;
    }

    public function seedFromBundledDatabaseIfEmpty(?string $sourcePath = null): bool
    {
        if (! config('nativephp-internal.running')) {
            return false;
        }

        $sourcePath ??= $this->defaultSeedDatabasePath();

        if (! is_file($sourcePath) || $this->hasApplicationData()) {
            return false;
        }

        $connection = DB::connection();
        $attachedDatabase = 'bundled_seed_'.substr(md5($sourcePath.microtime()), 0, 12);
        $foreignKeysWereEnabled = $this->foreignKeysEnabled($connection);

        $connection->statement(
            "ATTACH DATABASE '{$this->escapeSqlitePath($sourcePath)}' AS {$attachedDatabase}"
        );

        try {
            if ($connection->transactionLevel() === 0) {
                $connection->statement('PRAGMA foreign_keys = OFF');
            }

            foreach (self::APPLICATION_TABLES as $table) {
                if (
                    ! $this->tableExists($connection, $table)
                    || ! $this->attachedTableExists($connection, $attachedDatabase, $table)
                ) {
                    continue;
                }

                $this->copyTable($connection, $attachedDatabase, $table);
            }
        } finally {
            if ($connection->transactionLevel() === 0 && $foreignKeysWereEnabled) {
                $connection->statement('PRAGMA foreign_keys = ON');
            }

            if ($connection->transactionLevel() === 0) {
                $connection->statement("DETACH DATABASE {$attachedDatabase}");
            }
        }

        return true;
    }

    public static function bundledSeedDatabasePath(): string
    {
        return config('nativephp.seed_database_path', database_path(self::BUNDLED_SEED_DATABASE));
    }

    private function defaultSeedDatabasePath(): string
    {
        $bundledSeedPath = self::bundledSeedDatabasePath();

        if (is_file($bundledSeedPath)) {
            return $bundledSeedPath;
        }

        return database_path('database.sqlite');
    }

    private function hasApplicationData(): bool
    {
        foreach (self::APPLICATION_TABLES as $table) {
            if (! $this->tableExists(DB::connection(), $table)) {
                continue;
            }

            if ((int) DB::table($table)->count() > 0) {
                return true;
            }
        }

        return false;
    }

    private function tableExists(ConnectionInterface $connection, string $table): bool
    {
        return $connection
            ->table('sqlite_master')
            ->where('type', 'table')
            ->where('name', $table)
            ->exists();
    }

    private function copyTable(ConnectionInterface $connection, string $attachedDatabase, string $table): void
    {
        $columns = collect($connection->select(
            "PRAGMA {$attachedDatabase}.table_info({$this->quoteIdentifier($table)})"
        ))
            ->sortBy('cid')
            ->pluck('name')
            ->all();

        if ($columns === []) {
            return;
        }

        $columnList = collect($columns)
            ->map(fn (string $column): string => $this->quoteIdentifier($column))
            ->implode(', ');

        $quotedTable = $this->quoteIdentifier($table);

        $connection->statement(
            "INSERT INTO {$quotedTable} ({$columnList}) SELECT {$columnList} FROM {$attachedDatabase}.{$quotedTable}"
        );
    }

    private function foreignKeysEnabled(ConnectionInterface $connection): bool
    {
        $result = $connection->selectOne('PRAGMA foreign_keys');

        return (bool) ($result->foreign_keys ?? 0);
    }

    private function attachedTableExists(ConnectionInterface $connection, string $attachedDatabase, string $table): bool
    {
        return $connection
            ->table("{$attachedDatabase}.sqlite_master")
            ->where('type', 'table')
            ->where('name', $table)
            ->exists();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function escapeSqlitePath(string $path): string
    {
        return str_replace("'", "''", $path);
    }
}
