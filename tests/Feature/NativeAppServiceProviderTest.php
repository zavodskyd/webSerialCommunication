<?php

use App\Providers\NativeAppServiceProvider;
use App\Support\StartupCoordinator;
use Native\Desktop\Facades\Alert;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    $this->nativeWindow = new NativeWindow('main');
    $this->windowManager = Window::fake()->alwaysReturnWindows([$this->nativeWindow]);
});

test('native app opens the main window maximized but not fullscreen', function () {
    $providerSource = file_get_contents(app_path('Providers/NativeAppServiceProvider.php'));
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')->once();

    app(NativeAppServiceProvider::class)->boot();

    $this->windowManager->assertOpened('main');
    expect($this->nativeWindow->fullscreen)->toBeFalse()
        ->and($this->nativeWindow->autoHideMenuBar)->toBeFalse()
        ->and($providerSource)->toContain('->maximized()')
        ->and($providerSource)->not->toContain('->fullscreen()')
        ->and($providerSource)->not->toContain('->hideMenu()');
});

test('failed startup offers retry and relaunches without opening the main window', function () {
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('migration exploded'));

    mockStartupFailureAlert(selectedButton: 0);
    App::shouldReceive('relaunch')->once();
    App::shouldReceive('quit')->never();

    app(NativeAppServiceProvider::class)->boot();

    $this->windowManager->assertOpenedCount(0);
});

test('retry starts the coordinator again and opens the app after migrations succeed', function () {
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')
        ->once()
        ->ordered()
        ->andThrow(new RuntimeException('migration exploded'));
    $coordinator->shouldReceive('run')
        ->once()
        ->ordered();

    mockStartupFailureAlert(selectedButton: 0);
    App::shouldReceive('relaunch')->once();
    App::shouldReceive('quit')->never();

    $provider = app(NativeAppServiceProvider::class);
    $provider->boot();
    $this->windowManager->assertOpenedCount(0);

    $provider->boot();

    $this->windowManager->assertOpened('main');
});

test('failed startup can safely quit without opening the main window', function () {
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('migration exploded'));

    mockStartupFailureAlert(selectedButton: 1);
    App::shouldReceive('relaunch')->never();
    App::shouldReceive('quit')->once();

    app(NativeAppServiceProvider::class)->boot();

    $this->windowManager->assertOpenedCount(0);
});

test('failed recovery dialog logs the failure and safely quits', function () {
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('migration exploded'));

    Alert::shouldReceive('new')
        ->once()
        ->andThrow(new RuntimeException('dialog exploded'));
    App::shouldReceive('relaunch')->never();
    App::shouldReceive('quit')->once();

    app(NativeAppServiceProvider::class)->boot();

    $this->windowManager->assertOpenedCount(0);
});

function mockStartupFailureAlert(int $selectedButton): void
{
    Alert::shouldReceive('new')->once()->andReturnSelf();
    Alert::shouldReceive('title')
        ->once()
        ->with('Aktualizáciu databázy sa nepodarilo dokončiť')
        ->andReturnSelf();
    Alert::shouldReceive('detail')
        ->once()
        ->with('Aplikácia nemôže bezpečne pokračovať. Podrobnosti sú uložené v aplikačnom logu.')
        ->andReturnSelf();
    Alert::shouldReceive('buttons')
        ->once()
        ->with(['Skúsiť znova', 'Ukončiť aplikáciu'])
        ->andReturnSelf();
    Alert::shouldReceive('defaultId')->once()->with(0)->andReturnSelf();
    Alert::shouldReceive('cancelId')->once()->with(1)->andReturnSelf();
    Alert::shouldReceive('show')
        ->once()
        ->with('Skontrolujte prístup k databáze a skúste aktualizáciu znova.')
        ->andReturn($selectedButton);
}
