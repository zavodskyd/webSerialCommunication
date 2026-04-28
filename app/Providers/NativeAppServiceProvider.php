<?php

namespace App\Providers;

use App\Support\NativeDatabaseBootstrapper;
use App\Support\SerialHelperTokens;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        $this->wireSerialHelperLogPiping();
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
    /**
     * Pipe stdout/stderr/exit/error from the Node serial helper into Laravel
     * logs. Without this, helper crashes are invisible — the helper's own
     * log file requires the helper to actually start writing, and a fork
     * failure (missing native binary, bad path) never reaches that point.
     *
     * Subscribing here rather than via App\Listeners\… keeps the wiring
     * scoped to the NativePHP runtime — these events don't fire in dev/web.
     */
    private function wireSerialHelperLogPiping(): void
    {
        Event::listen('Native\\Desktop\\Events\\ChildProcess\\MessageReceived', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                Log::info('serial-helper stdout', ['data' => $event->data ?? '']);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\ErrorReceived', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                Log::error('serial-helper stderr', ['data' => $event->data ?? '']);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\ProcessExited', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                Log::error('serial-helper process exited', [
                    'code' => $event->code ?? null,
                    'signal' => $event->signal ?? null,
                ]);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\StartupError', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                Log::error('serial-helper startup error', [
                    'error' => $event->error ?? 'unknown',
                ]);
            }
        });
    }

    private function bootSerialHelperIfEnabled(): void
    {
        if (config('serial.driver') !== 'node-helper') {
            Log::info('serial-helper: driver is not node-helper, skipping spawn', [
                'driver' => config('serial.driver'),
            ]);

            return;
        }

        // Ensure the bearer token exists before the helper starts so it can
        // read it on first launch.
        $token = SerialHelperTokens::current();

        $scriptPath = $this->resolveSerialHelperScript();

        if ($scriptPath === null) {
            Log::error('serial-helper: helper script not found at any candidate path', [
                'candidates' => $this->serialHelperScriptCandidates(),
                'base_path' => base_path(),
            ]);

            return;
        }

        Log::info('serial-helper: spawning child process', [
            'script' => $scriptPath,
            'laravel_base' => base_path(),
        ]);

        try {
            ChildProcess::node(
                cmd: [$scriptPath],
                alias: 'serial-helper',
                env: [
                    'LARAVEL_BASE' => base_path(),
                    'LARAVEL_URL' => config('app.url') ?: 'http://127.0.0.1:8101',
                    'SERIAL_HELPER_TOKEN' => $token,
                ],
                persistent: true,
            );
        } catch (\Throwable $e) {
            Log::error('serial-helper: ChildProcess::node failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function serialHelperScriptCandidates(): array
    {
        return [
            // Preferred: helper bundled with NativePHP electron resources.
            base_path('nativephp/electron/serial-helper.js'),
            // Fallback: in case the packaged .exe lays out paths differently
            // and Laravel app sits beside electron resources.
            base_path('../app/serial-helper.js'),
            base_path('../../app/serial-helper.js'),
            // Final fallback: helper copy living inside the Laravel app tree
            // (created by the build:stamp-version command if missing — this
            // is what guarantees base_path() resolves it in the packaged build).
            base_path('serial-helper.js'),
        ];
    }

    private function resolveSerialHelperScript(): ?string
    {
        foreach ($this->serialHelperScriptCandidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
