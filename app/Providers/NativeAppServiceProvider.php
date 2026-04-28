<?php

namespace App\Providers;

use App\Support\NativeDatabaseBootstrapper;
use App\Support\SerialHelperDiagnostics;
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
                $data = $event->data ?? '';
                Log::info('serial-helper stdout', ['data' => $data]);
                SerialHelperDiagnostics::record('info', 'helper stdout', ['data' => $data]);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\ErrorReceived', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                $data = $event->data ?? '';
                Log::error('serial-helper stderr', ['data' => $data]);
                SerialHelperDiagnostics::record('error', 'helper stderr', ['data' => $data]);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\ProcessExited', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                $payload = ['code' => $event->code ?? null, 'signal' => $event->signal ?? null];
                Log::error('serial-helper process exited', $payload);
                SerialHelperDiagnostics::record('error', 'helper exited', $payload);
            }
        });

        Event::listen('Native\\Desktop\\Events\\ChildProcess\\StartupError', function ($event) {
            if (($event->alias ?? null) === 'serial-helper') {
                $payload = ['error' => $event->error ?? 'unknown'];
                Log::error('serial-helper startup error', $payload);
                SerialHelperDiagnostics::record('error', 'helper startup error', $payload);
            }
        });
    }

    private function bootSerialHelperIfEnabled(): void
    {
        if (config('serial.driver') !== 'node-helper') {
            $msg = 'driver is not node-helper, skipping spawn';
            Log::info('serial-helper: '.$msg, ['driver' => config('serial.driver')]);
            SerialHelperDiagnostics::record('info', $msg, ['driver' => config('serial.driver')]);

            return;
        }

        // Ensure the bearer token exists before the helper starts so it can
        // read it on first launch.
        $token = SerialHelperTokens::current();

        $candidates = $this->serialHelperScriptCandidates();
        $candidatesProbe = array_map(fn (string $p) => ['path' => $p, 'exists' => is_file($p)], $candidates);
        SerialHelperDiagnostics::record('info', 'script candidate probe', ['candidates' => $candidatesProbe]);

        $scriptPath = $this->resolveSerialHelperScript();

        if ($scriptPath === null) {
            Log::error('serial-helper: helper script not found at any candidate path', [
                'candidates' => $candidates,
                'base_path' => base_path(),
            ]);
            SerialHelperDiagnostics::record('error', 'helper script not found', [
                'candidates' => $candidatesProbe,
                'base_path' => base_path(),
            ]);

            return;
        }

        Log::info('serial-helper: spawning child process', [
            'script' => $scriptPath,
            'laravel_base' => base_path(),
        ]);
        SerialHelperDiagnostics::record('info', 'spawning child process', [
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
            SerialHelperDiagnostics::record('info', 'ChildProcess::node call returned without exception');
        } catch (\Throwable $e) {
            Log::error('serial-helper: ChildProcess::node failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
            SerialHelperDiagnostics::record('error', 'ChildProcess::node threw', [
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
