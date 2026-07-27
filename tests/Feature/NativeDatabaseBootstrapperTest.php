<?php

use App\Livewire\Election\ElectionEditor;
use App\Models\User;
use App\Models\Voting;
use App\Support\NativeDatabaseBootstrapper;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('runPendingMigrations short-circuits when not in nativephp runtime', function () {
    config(['nativephp-internal.running' => false]);

    $bootstrapper = new NativeDatabaseBootstrapper;

    expect($bootstrapper->runPendingMigrations())->toBeFalse();
});

test('runPendingMigrations invokes artisan migrate when nativephp is running', function () {
    config(['nativephp-internal.running' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    $bootstrapper = new NativeDatabaseBootstrapper;

    expect($bootstrapper->runPendingMigrations())->toBeTrue();
});

test('runPendingMigrations fails when artisan migrate returns a non-zero exit code', function () {
    config(['nativephp-internal.running' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(1);

    $bootstrapper = new NativeDatabaseBootstrapper;

    $bootstrapper->runPendingMigrations();
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

        expect($databaseConfig['connections']['sqlite']['database'])
            ->toBe($nativeDatabasePath);

        config([
            'database.connections.native_install_test' => $databaseConfig['connections']['sqlite'],
        ]);

        Artisan::call('migrate', [
            '--database' => 'native_install_test',
            '--force' => true,
        ]);

        $nativeDatabase = new PDO("sqlite:{$nativeDatabasePath}");
        $migratedUsersTable = $nativeDatabase
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")
            ->fetchColumn();

        expect($migratedUsersTable)->toBe('users');
    } finally {
        config([
            'database.connections.native_install_test' => null,
        ]);

        DB::purge('native_install_test');
        restoreEnvironmentValue('NATIVEPHP_DATABASE_PATH', $originalNativeDatabasePath);
        restoreEnvironmentValue('DB_DATABASE', $originalDatabasePath);
        @unlink($nativeDatabasePath);
    }
});

test('it imports bundled application data when native database is empty', function () {
    config(['nativephp-internal.running' => true]);

    $sourcePath = createBundledSeedDatabase([
        'email' => 'admin@example.com',
        'device_number' => '001',
        'voting_name' => 'Ostre hlasovanie',
    ]);

    $imported = app(NativeDatabaseBootstrapper::class)
        ->seedFromBundledDatabaseIfEmpty($sourcePath);

    expect($imported)->toBeTrue();
    expect(DB::table('users')->where('email', 'admin@example.com')->exists())->toBeTrue();
    expect(DB::table('devices')->where('device_number', '001')->exists())->toBeTrue();
    expect(DB::table('votings')->where('name', 'Ostre hlasovanie')->exists())->toBeTrue();
});

test('it imports bundled election data and opens the editor', function () {
    config(['nativephp-internal.running' => true]);

    $sourcePath = createBundledElectionSeedDatabase();

    try {
        $imported = app(NativeDatabaseBootstrapper::class)
            ->seedFromBundledDatabaseIfEmpty($sourcePath);

        $voting = Voting::query()->where('name', 'Pripravené voľby')->firstOrFail();

        expect($imported)->toBeTrue()
            ->and($voting->voting_type)->toBe('election')
            ->and($voting->election)->not->toBeNull()
            ->and($voting->election->contests()->count())->toBe(1)
            ->and($voting->election->contests()->firstOrFail()->candidates()->count())->toBe(1)
            ->and($voting->election->deviceGroups()->firstOrFail()->ranges()->count())->toBe(1);

        $this->get(route('elections.edit', $voting))
            ->assertSuccessful()
            ->assertSeeLivewire(ElectionEditor::class)
            ->assertSeeText('Kandidátky súťaží');
    } finally {
        @unlink($sourcePath);
    }
});

test('it imports the bundled native seed database by default', function () {
    config(['nativephp-internal.running' => true]);

    $seedPath = tempnam(sys_get_temp_dir(), 'native-default-seed-');
    config(['nativephp.seed_database_path' => $seedPath]);

    try {
        $sourcePath = createBundledSeedDatabase([
            'email' => 'bundled@example.com',
            'device_number' => '777',
            'voting_name' => 'Bundled hlasovanie',
        ]);

        copy($sourcePath, $seedPath);
        @unlink($sourcePath);

        $imported = app(NativeDatabaseBootstrapper::class)
            ->seedFromBundledDatabaseIfEmpty();

        expect($imported)->toBeTrue();
        expect(DB::table('users')->where('email', 'bundled@example.com')->exists())->toBeTrue();
        expect(DB::table('devices')->where('device_number', '777')->exists())->toBeTrue();
        expect(DB::table('votings')->where('name', 'Bundled hlasovanie')->exists())->toBeTrue();
    } finally {
        @unlink($seedPath);
    }
});

test('it does not overwrite an existing native database', function () {
    config(['nativephp-internal.running' => true]);

    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $sourcePath = createBundledSeedDatabase([
        'email' => 'admin@example.com',
        'device_number' => '001',
        'voting_name' => 'Ostre hlasovanie',
    ]);

    $imported = app(NativeDatabaseBootstrapper::class)
        ->seedFromBundledDatabaseIfEmpty($sourcePath);

    expect($imported)->toBeFalse();
    expect(DB::table('users')->pluck('email')->all())->toBe(['existing@example.com']);
});

test('native seed preparation copies the current sqlite database to the build seed path', function () {
    $sourcePath = createBundledSeedDatabase([
        'email' => 'prepared@example.com',
        'device_number' => '123',
        'voting_name' => 'Prepared hlasovanie',
    ]);
    $destinationPath = tempnam(sys_get_temp_dir(), 'native-seed-destination-');

    try {
        $exitCode = Artisan::call('native:prepare-seed-database', [
            '--source' => $sourcePath,
            '--destination' => $destinationPath,
        ]);

        $preparedDatabase = new PDO("sqlite:{$destinationPath}");
        $preparedEmail = $preparedDatabase
            ->query("SELECT email FROM users WHERE email = 'prepared@example.com'")
            ->fetchColumn();

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain('NativePHP seed database prepared');
        expect($preparedEmail)->toBe('prepared@example.com');
    } finally {
        @unlink($sourcePath);
        @unlink($destinationPath);
    }
});

/**
 * @param  array{email: string, device_number: string, voting_name: string}  $data
 */
function createBundledSeedDatabase(array $data): string
{
    $sourcePath = tempnam(sys_get_temp_dir(), 'native-seed-');
    $source = new PDO("sqlite:{$sourcePath}");
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $source->exec('
        CREATE TABLE users (
            id integer primary key,
            name varchar not null,
            email varchar not null,
            email_verified_at datetime null,
            password varchar not null,
            remember_token varchar null,
            created_at datetime null,
            updated_at datetime null
        )
    ');

    $source->exec('
        CREATE TABLE devices (
            id integer primary key,
            device_number varchar not null,
            code_a varchar not null,
            code_b varchar not null,
            code_c varchar not null,
            code_d varchar not null,
            code_e varchar not null,
            code_f varchar not null,
            code_ruka varchar not null,
            created_at datetime null,
            updated_at datetime null
        )
    ');

    $source->exec('
        CREATE TABLE votings (
            id integer primary key,
            name varchar not null,
            status varchar not null,
            question_label varchar not null,
            title varchar null,
            header_text text null,
            logo_path varchar null,
            default_response_time_seconds integer not null,
            started_at datetime null,
            finished_at datetime null,
            created_at datetime null,
            updated_at datetime null,
            auto_show_results tinyint(1) not null,
            current_voting_question_id integer null,
            runtime_remaining_seconds integer not null,
            runtime_timer_running tinyint(1) not null,
            runtime_collector_enabled tinyint(1) not null,
            runtime_results_visible tinyint(1) not null
        )
    ');

    $timestamp = now()->toDateTimeString();

    $source->prepare('
        INSERT INTO users (
            id, name, email, email_verified_at, password, remember_token, created_at, updated_at
        ) VALUES (
            :id, :name, :email, :email_verified_at, :password, :remember_token, :created_at, :updated_at
        )
    ')->execute([
        'id' => 100,
        'name' => 'Admin',
        'email' => $data['email'],
        'email_verified_at' => $timestamp,
        'password' => bcrypt('password'),
        'remember_token' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $source->prepare('
        INSERT INTO devices (
            id, device_number, code_a, code_b, code_c, code_d, code_e, code_f, code_ruka, created_at, updated_at
        ) VALUES (
            :id, :device_number, :code_a, :code_b, :code_c, :code_d, :code_e, :code_f, :code_ruka, :created_at, :updated_at
        )
    ')->execute([
        'id' => 200,
        'device_number' => $data['device_number'],
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $source->prepare('
        INSERT INTO votings (
            id, name, status, question_label, title, header_text, logo_path, default_response_time_seconds,
            started_at, finished_at, created_at, updated_at, auto_show_results, current_voting_question_id,
            runtime_remaining_seconds, runtime_timer_running, runtime_collector_enabled, runtime_results_visible
        ) VALUES (
            :id, :name, :status, :question_label, :title, :header_text, :logo_path, :default_response_time_seconds,
            :started_at, :finished_at, :created_at, :updated_at, :auto_show_results, :current_voting_question_id,
            :runtime_remaining_seconds, :runtime_timer_running, :runtime_collector_enabled, :runtime_results_visible
        )
    ')->execute([
        'id' => 300,
        'name' => $data['voting_name'],
        'status' => 'draft',
        'question_label' => 'Hlasovanie',
        'title' => null,
        'header_text' => null,
        'logo_path' => null,
        'default_response_time_seconds' => 30,
        'started_at' => null,
        'finished_at' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
        'auto_show_results' => 1,
        'current_voting_question_id' => null,
        'runtime_remaining_seconds' => 0,
        'runtime_timer_running' => 0,
        'runtime_collector_enabled' => 0,
        'runtime_results_visible' => 0,
    ]);

    return $sourcePath;
}

function createBundledElectionSeedDatabase(): string
{
    $sourcePath = createBundledSeedDatabase([
        'email' => 'election@example.com',
        'device_number' => '001',
        'voting_name' => 'Pripravené voľby',
    ]);
    $source = new PDO("sqlite:{$sourcePath}");
    $source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $source->exec("ALTER TABLE votings ADD COLUMN voting_type varchar NOT NULL DEFAULT 'standard'");
    $source->exec("UPDATE votings SET voting_type = 'election' WHERE id = 300");
    $source->exec('
        CREATE TABLE elections (
            id integer primary key,
            voting_id integer not null,
            status varchar not null default "draft",
            created_at datetime null,
            updated_at datetime null
        );
        CREATE TABLE device_groups (
            id integer primary key,
            election_id integer not null,
            name varchar not null,
            sort_order integer not null,
            is_active tinyint(1) not null,
            quorum_participant_count integer null,
            created_at datetime null,
            updated_at datetime null
        );
        CREATE TABLE device_group_ranges (
            id integer primary key,
            device_group_id integer not null,
            start_number integer not null,
            end_number integer not null,
            created_at datetime null,
            updated_at datetime null
        );
        CREATE TABLE election_contests (
            id integer primary key,
            election_id integer not null,
            device_group_id integer null,
            key varchar not null,
            name varchar not null,
            seat_count integer not null,
            sort_order integer not null,
            created_at datetime null,
            updated_at datetime null
        );
        CREATE TABLE election_candidates (
            id integer primary key,
            election_contest_id integer not null,
            first_name varchar not null,
            last_name varchar not null,
            status varchar not null,
            created_at datetime null,
            updated_at datetime null
        );
    ');

    $timestamp = now()->toDateTimeString();
    $source->prepare('INSERT INTO elections (id, voting_id, status, created_at, updated_at) VALUES (400, 300, "draft", :created_at, :updated_at)')
        ->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);
    $source->prepare('INSERT INTO device_groups (id, election_id, name, sort_order, is_active, quorum_participant_count, created_at, updated_at) VALUES (500, 400, "Hliny", 1, 1, 120, :created_at, :updated_at)')
        ->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);
    $source->prepare('INSERT INTO device_group_ranges (id, device_group_id, start_number, end_number, created_at, updated_at) VALUES (600, 500, 1, 20, :created_at, :updated_at)')
        ->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);
    $source->prepare('INSERT INTO election_contests (id, election_id, device_group_id, key, name, seat_count, sort_order, created_at, updated_at) VALUES (700, 400, 500, "chairperson", "Predseda", 1, 1, :created_at, :updated_at)')
        ->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);
    $source->prepare('INSERT INTO election_candidates (id, election_contest_id, first_name, last_name, status, created_at, updated_at) VALUES (800, 700, "Jana", "Nováková", "approved", :created_at, :updated_at)')
        ->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);

    return $sourcePath;
}

function restoreEnvironmentValue(string $key, mixed $value): void
{
    $repository = Env::getRepository();

    if ($value === null) {
        $repository->clear($key);

        return;
    }

    $repository->set($key, (string) $value);
}
