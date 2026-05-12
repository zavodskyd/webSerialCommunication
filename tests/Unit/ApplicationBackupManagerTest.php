<?php

use App\Support\ApplicationBackupManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

test('restoreDatabaseFrom replaces the current sqlite file and reconnects', function () {
    $manager = app(ApplicationBackupManager::class);
    $originalDatabase = config('database.connections.sqlite.database');
    $targetDatabasePath = tempnam(sys_get_temp_dir(), 'backup-target-');
    $sourceDatabasePath = tempnam(sys_get_temp_dir(), 'backup-source-');

    configureDefaultFileBackedDatabase($targetDatabasePath);

    try {
        DB::table('users')->insert([
            'name' => 'Old Sqlite User',
            'email' => 'old-sqlite@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        createMigratedDatabaseBackup($sourceDatabasePath, 'backup_source_test', function (): void {
            DB::connection('backup_source_test')->table('users')->insert([
                'name' => 'Restored Sqlite User',
                'email' => 'restored-sqlite@example.com',
                'password' => bcrypt('secret'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $manager->restoreDatabaseFrom($sourceDatabasePath);

        expect(DB::table('users')->pluck('email')->all())->toBe(['restored-sqlite@example.com']);
    } finally {
        restoreDefaultDatabase($originalDatabase);
        DB::purge('backup_source_test');
        config(['database.connections.backup_source_test' => null]);
        @unlink($targetDatabasePath);
        @unlink($sourceDatabasePath);
    }
});

test('validateDatabaseBackup rejects sqlite files without required application tables', function () {
    $manager = app(ApplicationBackupManager::class);
    $databasePath = tempnam(sys_get_temp_dir(), 'backup-invalid-');

    try {
        $database = new PDO("sqlite:{$databasePath}");
        $database->exec('CREATE TABLE random_table (id integer primary key)');

        expect(fn () => $manager->validateDatabaseBackup($databasePath))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($databasePath);
    }
});

function configureDefaultFileBackedDatabase(string $databasePath): void
{
    config([
        'database.connections.sqlite.database' => $databasePath,
    ]);

    DB::purge('sqlite');
    Artisan::call('migrate', [
        '--force' => true,
        '--no-interaction' => true,
    ]);
    DB::purge('sqlite');
    DB::reconnect('sqlite');
}

function restoreDefaultDatabase(string $databasePath): void
{
    config([
        'database.connections.sqlite.database' => $databasePath,
    ]);

    DB::purge('sqlite');
    DB::reconnect('sqlite');
}

function createMigratedDatabaseBackup(string $databasePath, string $connectionName, callable $seed): void
{
    $databaseConfig = config('database.connections.sqlite');

    config([
        "database.connections.{$connectionName}" => [
            ...$databaseConfig,
            'database' => $databasePath,
        ],
    ]);

    Artisan::call('migrate', [
        '--database' => $connectionName,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    $seed();
}
