<?php

use App\Support\BuildVersion;
use App\Support\NativeDatabaseBootstrapper;
use App\Support\NativeStartupState;
use App\Support\SerialAgentTokens;
use App\Support\StartupCoordinator;
use Native\Desktop\Facades\ChildProcess;

beforeEach(function () {
    config(['serial.driver' => 'test-disabled']);

    $this->startupStatePath = app(NativeStartupState::class)->path();
    $this->originalStartupState = is_file($this->startupStatePath)
        ? file_get_contents($this->startupStatePath)
        : null;

    $this->buildVersionPath = BuildVersion::stampFilePath();
    $this->originalBuildVersion = is_file($this->buildVersionPath)
        ? file_get_contents($this->buildVersionPath)
        : null;

    @unlink($this->startupStatePath);
    writeBuildVersion('2026.06.05-test');
});

afterEach(function () {
    restoreFile($this->startupStatePath, $this->originalStartupState);
    restoreFile($this->buildVersionPath, $this->originalBuildVersion);
    @unlink(SerialAgentTokens::tokenPath());
});

test('first native boot creates the schema without trying to back up an empty database', function () {
    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->never();
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnTrue();

    app(StartupCoordinator::class)->run();

    expect(app(NativeStartupState::class)->lastStartedVersion())->toBe('2026.06.05-test');
});

test('startup state records the last completed progress step', function () {
    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->never();
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnTrue();

    app(StartupCoordinator::class)->run();

    $state = app(NativeStartupState::class)->load();

    expect($state['current_step'])->toBe('mark-startup-ready')
        ->and($state['current_status'])->toBe('ok');
});

test('unchanged build version skips backup and migrations', function () {
    app(NativeStartupState::class)->markSuccessful('2026.06.05-test');

    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnTrue();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->never();
    $bootstrapper->shouldReceive('runPendingMigrations')->never();

    app(StartupCoordinator::class)->run();

    expect(app(NativeStartupState::class)->lastStartedVersion())->toBe('2026.06.05-test');
});

test('changed build version backs up existing data before migrations', function () {
    app(NativeStartupState::class)->markSuccessful('2026.06.04-test');

    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnTrue();
    $bootstrapper->shouldReceive('backupBeforeMigrations')
        ->once()
        ->with('2026.06.05-test')
        ->andReturn('/tmp/pre-migration.sqlite');
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnTrue();

    app(StartupCoordinator::class)->run();

    expect(app(NativeStartupState::class)->lastStartedVersion())->toBe('2026.06.05-test');
});

test('backup failure prevents migrations and records the failed step', function () {
    app(NativeStartupState::class)->markSuccessful('2026.06.04-test');

    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnTrue();
    $bootstrapper->shouldReceive('backupBeforeMigrations')
        ->once()
        ->andThrow(new RuntimeException('backup exploded'));
    $bootstrapper->shouldReceive('runPendingMigrations')->never();

    expect(fn () => app(StartupCoordinator::class)->run())
        ->toThrow(RuntimeException::class, 'backup exploded');

    $state = app(NativeStartupState::class)->load();

    expect($state['last_started_version'])->toBe('2026.06.04-test')
        ->and($state['last_failed_step'])->toBe('backup-database-before-migrations');
});

test('migration failure state persists expected metadata after a successful backup', function () {
    app(NativeStartupState::class)->markSuccessful('2026.06.04-test');

    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnTrue();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->once()->andReturn('/tmp/pre-migration.sqlite');
    $bootstrapper->shouldReceive('runPendingMigrations')
        ->once()
        ->andThrow(new RuntimeException('migration exploded'));

    expect(fn () => app(StartupCoordinator::class)->run())
        ->toThrow(RuntimeException::class, 'migration exploded');

    $state = app(NativeStartupState::class)->load();

    expect($state['last_started_version'])->toBe('2026.06.04-test')
        ->and($state['last_failed_step'])->toBe('maybe-run-migrations')
        ->and($state['last_failed_message'])->toBe('migration exploded');
});

test('native startup starts the rust serial agent and bridge when configured', function () {
    config([
        'serial.driver' => 'rust-agent',
        'serial.agent_executable_path' => $agentPath = storage_path('framework/testing-serial-agent.exe'),
    ]);

    @mkdir(dirname($agentPath), 0755, true);
    file_put_contents($agentPath, 'fake exe');

    $childProcesses = ChildProcess::fake();
    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->never();
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnTrue();

    app(StartupCoordinator::class)->run();

    $childProcesses->assertStarted(function (array|string $cmd, string $alias, ?string $cwd, ?array $env, bool $persistent) use ($agentPath): bool {
        return $alias === 'serial-agent'
            && $cmd === [$agentPath]
            && $cwd === dirname($agentPath)
            && $persistent === true
            && is_array($env)
            && ($env['STORAGE_PATH'] ?? null) === storage_path()
            && ($env['INTERNAL_TOKEN'] ?? '') !== '';
    });

    $childProcesses->assertArtisan(function (array|string $cmd, string $alias, ?array $env, ?bool $persistent, ?array $iniSettings): bool {
        return $alias === 'serial-agent-bridge'
            && $cmd === ['serial-agent:bridge']
            && $persistent === true
            && $iniSettings === null
            && is_array($env)
            && ($env['STORAGE_PATH'] ?? null) === storage_path()
            && ($env['INTERNAL_TOKEN'] ?? '') !== '';
    });

    @unlink($agentPath);
});

test('missing rust serial agent fails startup before marking ready', function () {
    config([
        'serial.driver' => 'rust-agent',
        'serial.agent_executable_path' => storage_path('framework/missing-serial-agent.exe'),
    ]);

    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('hasDatabaseSchema')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('backupBeforeMigrations')->never();
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnTrue();

    expect(fn () => app(StartupCoordinator::class)->run())
        ->toThrow(RuntimeException::class, 'Rust serial agent executable not found');

    $state = app(NativeStartupState::class)->load();

    expect($state['last_started_version'] ?? null)->toBeNull()
        ->and($state['last_failed_step'])->toBe('start-rust-agent');
});

function writeBuildVersion(string $version): void
{
    @mkdir(dirname(BuildVersion::stampFilePath()), 0755, true);
    file_put_contents(BuildVersion::stampFilePath(), $version);
}

function restoreFile(string $path, ?string $contents): void
{
    if ($contents === null) {
        @unlink($path);

        return;
    }

    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);
}
