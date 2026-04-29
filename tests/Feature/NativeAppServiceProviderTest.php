<?php

use App\Providers\NativeAppServiceProvider;
use App\Support\NativeDatabaseBootstrapper;
use App\Support\SerialHelperTokens;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

test('native app opens the main window maximized but not fullscreen', function () {
    $nativeWindow = new NativeWindow('main');
    $windowManager = Window::fake()->alwaysReturnWindows([$nativeWindow]);
    $providerSource = file_get_contents(app_path('Providers/NativeAppServiceProvider.php'));
    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('seedFromBundledDatabaseIfEmpty')->once()->andReturnFalse();

    app(NativeAppServiceProvider::class)->boot();

    $windowManager->assertOpened('main');
    expect($nativeWindow->fullscreen)->toBeFalse()
        ->and($nativeWindow->autoHideMenuBar)->toBeFalse()
        ->and($providerSource)->toContain('->maximized()')
        ->and($providerSource)->not->toContain('->fullscreen()')
        ->and($providerSource)->not->toContain('->hideMenu()');
});

test('native app starts the rust serial agent and bridge when configured', function () {
    config([
        'serial.driver' => 'rust-agent',
        'serial.agent_executable_path' => $agentPath = storage_path('framework/testing-serial-agent.exe'),
    ]);

    @mkdir(dirname($agentPath), 0755, true);
    file_put_contents($agentPath, 'fake exe');
    @unlink(SerialHelperTokens::tokenPath());

    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);
    $childProcesses = ChildProcess::fake();
    $bootstrapper = $this->mock(NativeDatabaseBootstrapper::class);
    $bootstrapper->shouldReceive('runPendingMigrations')->once()->andReturnFalse();
    $bootstrapper->shouldReceive('seedFromBundledDatabaseIfEmpty')->once()->andReturnFalse();

    app(NativeAppServiceProvider::class)->boot();

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
    @unlink(SerialHelperTokens::tokenPath());
});
