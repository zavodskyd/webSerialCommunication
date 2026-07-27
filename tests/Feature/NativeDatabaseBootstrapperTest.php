<?php

use App\Support\NativeDatabaseBootstrapper;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->originalDefaultConnection = config('database.default');
});

afterEach(function () {
    DB::purge('native_database_test');
    config([
        'database.default' => $this->originalDefaultConnection,
        'database.connections.native_database_test' => null,
    ]);
});

test('runPendingMigrations short-circuits when not in nativephp runtime', function () {
    config(['nativephp-internal.running' => false]);

    expect(app(NativeDatabaseBootstrapper::class)->runPendingMigrations())->toBeFalse();
});

test('runPendingMigrations invokes artisan migrate when nativephp is running', function () {
    config(['nativephp-internal.running' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    expect(app(NativeDatabaseBootstrapper::class)->runPendingMigrations())->toBeTrue();
});

test('runPendingMigrations fails when artisan migrate returns a non-zero exit code', function () {
    config(['nativephp-internal.running' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(1);

    app(NativeDatabaseBootstrapper::class)->runPendingMigrations();
})->throws(RuntimeException::class, 'Native database migration failed with exit code 1.');

test('native install migrations run against the native sqlite database file', function () {
    $repository = Env::getRepository();
    $nativeDatabasePath = tempnam(sys_get_temp_dir(), 'native-db-');
    $bundledDatabasePath = database_path('database.sqlite');
    $originalNativeDatabasePath = Env::get('NATIVEPHP_DATABASE_PATH');
    $originalDatabasePath = Env::get('DB_DATABASE');

    try {
        $repository->set('NATIVEPHP_DATABASE_PATH', $nativeDatabasePath);
        $repository->set('DB_DATABASE', $bundledDatabasePath);

        $databaseConfig = require config_path('database.php');

        expect($databaseConfig['connections']['sqlite']['database'])->toBe($nativeDatabasePath);

        config(['database.connections.native_install_test' => $databaseConfig['connections']['sqlite']]);

        Artisan::call('migrate', [
            '--database' => 'native_install_test',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $nativeDatabase = new PDO("sqlite:{$nativeDatabasePath}");
        $migratedUsersTable = $nativeDatabase
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")
            ->fetchColumn();

        expect($migratedUsersTable)->toBe('users');
    } finally {
        DB::purge('native_install_test');
        config(['database.connections.native_install_test' => null]);
        restoreEnvironmentValue('NATIVEPHP_DATABASE_PATH', $originalNativeDatabasePath);
        restoreEnvironmentValue('DB_DATABASE', $originalDatabasePath);
        @unlink($nativeDatabasePath);
    }
});

test('hasDatabaseSchema recognizes a fresh and a migrated sqlite database', function () {
    config(['nativephp-internal.running' => true]);
    $databasePath = configureNativeDatabaseTestConnection();

    try {
        expect(app(NativeDatabaseBootstrapper::class)->hasDatabaseSchema())->toBeFalse();

        DB::statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration VARCHAR NOT NULL, batch INTEGER NOT NULL)');

        expect(app(NativeDatabaseBootstrapper::class)->hasDatabaseSchema())->toBeTrue();
    } finally {
        DB::purge('native_database_test');
        @unlink($databasePath);
    }
});

test('backupBeforeMigrations creates a consistent sqlite copy with existing data', function () {
    config(['nativephp-internal.running' => true]);
    $databasePath = configureNativeDatabaseTestConnection();
    $backupDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'native-backup-'.bin2hex(random_bytes(6));

    try {
        DB::statement('CREATE TABLE settings (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL)');
        DB::table('settings')->insert(['name' => 'preserved value']);

        $backupPath = app(NativeDatabaseBootstrapper::class)
            ->backupBeforeMigrations('2026.1.0', $backupDirectory);

        $backupDatabase = new PDO("sqlite:{$backupPath}");
        $value = $backupDatabase->query('SELECT name FROM settings')->fetchColumn();

        expect($backupPath)->not->toBeNull()
            ->and($backupPath)->toContain('pre-migration-2026.1.0-')
            ->and($value)->toBe('preserved value');
    } finally {
        DB::purge('native_database_test');
        @unlink($databasePath);

        if (is_dir($backupDirectory)) {
            app('files')->deleteDirectory($backupDirectory);
        }
    }
});

test('backupBeforeMigrations short-circuits outside nativephp runtime', function () {
    config(['nativephp-internal.running' => false]);

    expect(app(NativeDatabaseBootstrapper::class)->backupBeforeMigrations('2026.1.0'))->toBeNull();
});

test('production database seeder leaves application tables empty', function () {
    Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]);

    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('devices')->count())->toBe(0)
        ->and(DB::table('votings')->count())->toBe(0)
        ->and(DB::table('elections')->count())->toBe(0);
});

function configureNativeDatabaseTestConnection(): string
{
    $databasePath = tempnam(sys_get_temp_dir(), 'native-database-test-');

    config([
        'database.default' => 'native_database_test',
        'database.connections.native_database_test' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],
    ]);

    DB::purge('native_database_test');

    return $databasePath;
}

function restoreEnvironmentValue(string $key, mixed $value): void
{
    $repository = Env::getRepository();

    if ($value === null) {
        $repository->clear($key);

        return;
    }

    $repository->set($key, $value);
}
