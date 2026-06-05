<?php

use App\Providers\NativeAppServiceProvider;
use App\Support\StartupCoordinator;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

test('native app opens the main window maximized but not fullscreen', function () {
    $nativeWindow = new NativeWindow('main');
    $windowManager = Window::fake()->alwaysReturnWindows([$nativeWindow]);
    $providerSource = file_get_contents(app_path('Providers/NativeAppServiceProvider.php'));
    $coordinator = $this->mock(StartupCoordinator::class);
    $coordinator->shouldReceive('run')->once();

    app(NativeAppServiceProvider::class)->boot();

    $windowManager->assertOpened('main');
    expect($nativeWindow->fullscreen)->toBeFalse()
        ->and($nativeWindow->autoHideMenuBar)->toBeFalse()
        ->and($providerSource)->toContain('->maximized()')
        ->and($providerSource)->not->toContain('->fullscreen()')
        ->and($providerSource)->not->toContain('->hideMenu()');
});
