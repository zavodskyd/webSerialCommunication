<?php

namespace App\Providers;

use App\Support\StartupCoordinator;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Force log channel to 'single' on NativePHP runtime — the default
        // 'stack'/'daily' setup can fail silently inside a packaged .exe
        // (no laravel.log appears in Dušan's Win build). 'single' is the
        // simplest path: one append-only file with a deterministic name.
        config(['logging.default' => 'single']);

        Log::info('NativeAppServiceProvider::boot', [
            'serial_driver' => config('serial.driver'),
            'base_path' => base_path(),
            'storage_path' => storage_path(),
            'app_url' => config('app.url'),
        ]);

        app(StartupCoordinator::class)->run();

        Window::open()
            ->maximized();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
