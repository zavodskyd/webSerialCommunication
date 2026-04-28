<?php

namespace App\Providers;

use App\Support\NativeDatabaseBootstrapper;
use App\Support\SerialHelperTokens;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Run pending migrations BEFORE seed so schema additions land on
        // returning users (whose DB already has data from a prior build, so
        // seed-from-bundled is skipped). Without this, columns/tables added
        // after the user's first install never appear and queries 500.
        app(NativeDatabaseBootstrapper::class)->runPendingMigrations();
        app(NativeDatabaseBootstrapper::class)->seedFromBundledDatabaseIfEmpty();

        $this->bootSerialHelperIfEnabled();

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

    /**
     * Spawn the Node serial helper as a NativePHP child process when the
     * SERIAL_DRIVER env is set to 'node-helper'. The helper takes over
     * USB-serial reading from the legacy Web Serial API in the operator
     * console — see docs/design-intent.md for the rationale.
     *
     * Default driver is 'web-serial', so the helper does NOT spawn unless
     * Dušan explicitly opts in via .env. This preserves rollback safety.
     */
    private function bootSerialHelperIfEnabled(): void
    {
        if (config('serial.driver') !== 'node-helper') {
            return;
        }

        // Ensure the bearer token exists before the helper starts so it can
        // read it on first launch.
        $token = SerialHelperTokens::current();

        // Helper script lives inside nativephp/electron/ so its native deps
        // (serialport) are bundled by electron-builder into the .exe — no
        // npm install on the target Windows machine. The script's require()
        // resolves serialport from nativephp/electron/node_modules/.
        ChildProcess::node(
            cmd: [base_path('nativephp/electron/serial-helper.js')],
            alias: 'serial-helper',
            env: [
                'LARAVEL_BASE' => base_path(),
                'LARAVEL_URL' => config('app.url') ?: 'http://127.0.0.1:8101',
                'SERIAL_HELPER_TOKEN' => $token,
            ],
            persistent: true,
        );
    }
}
