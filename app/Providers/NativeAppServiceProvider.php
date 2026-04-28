<?php

namespace App\Providers;

use App\Support\NativeDatabaseBootstrapper;
use App\Support\SerialHelperDiagnostics;
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

        // Run pending migrations BEFORE seed so schema additions land on
        // returning users (whose DB already has data from a prior build, so
        // seed-from-bundled is skipped). Without this, columns/tables added
        // after the user's first install never appear and queries 500.
        app(NativeDatabaseBootstrapper::class)->runPendingMigrations();
        app(NativeDatabaseBootstrapper::class)->seedFromBundledDatabaseIfEmpty();

        // Helper is spawned by Electron main process (nativephp/electron/src/main/index.js)
        // not by PHP — see commit message for why. PHP just observes the
        // helper port file once Electron has started it.
        SerialHelperDiagnostics::record('info', 'NativePHP boot — helper lifecycle owned by Electron main', [
            'driver' => config('serial.driver'),
        ]);

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
