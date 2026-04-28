<?php

use App\Providers\NativeAppServiceProvider;
use App\Support\NativeDatabaseBootstrapper;
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
