<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\ChildProcess;

class StartupCoordinator
{
    public function __construct(
        private readonly NativeDatabaseBootstrapper $databaseBootstrapper,
        private readonly NativeStartupState $state,
    ) {}

    public function run(): void
    {
        $version = $this->runStep('resolve-build-version', fn (): string => BuildVersion::current());

        $this->runStep('load-startup-state', fn (): array => $this->state->load());

        $hasDatabaseSchema = $this->runStep(
            'check-database-schema',
            fn (): bool => $this->databaseBootstrapper->hasDatabaseSchema()
        );

        $shouldRunMigrations = ! $hasDatabaseSchema
            || $this->state->lastStartedVersion() !== $version;

        if ($shouldRunMigrations) {
            if ($hasDatabaseSchema) {
                $this->runStep(
                    'backup-database-before-migrations',
                    fn (): ?string => $this->databaseBootstrapper->backupBeforeMigrations($version)
                );
            } else {
                $this->logStep(
                    'backup-database-before-migrations',
                    'ok',
                    'Skipped because the database schema does not exist yet.'
                );
            }

            $this->runStep(
                'maybe-run-migrations',
                fn (): bool => $this->databaseBootstrapper->runPendingMigrations()
            );
        } else {
            $this->logStep('backup-database-before-migrations', 'ok', 'Skipped for unchanged build version.');
            $this->logStep('maybe-run-migrations', 'ok', 'Skipped for unchanged build version.');
        }

        $this->runStep('start-rust-agent', fn (): bool => $this->startRustSerialAgent());
        $this->runStep('start-laravel-serial-bridge', fn (): bool => $this->startLaravelSerialBridge());
        $this->runStep('mark-startup-ready', fn (): bool => $this->markReady($version));
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function runStep(string $step, callable $callback): mixed
    {
        $startedAt = hrtime(true);
        $this->logStep($step, 'running');

        try {
            $result = $callback();
            $this->logStep($step, 'ok', durationMs: $this->durationMs($startedAt));

            return $result;
        } catch (\Throwable $exception) {
            $this->state->markFailed($step, $exception->getMessage());
            $this->logStep($step, 'failed', $exception->getMessage(), $this->durationMs($startedAt));

            throw $exception;
        }
    }

    private function startRustSerialAgent(): bool
    {
        if (config('serial.driver') !== 'rust-agent') {
            return false;
        }

        $agentPath = SerialAgentFiles::executablePath();

        if (! is_file($agentPath)) {
            throw new \RuntimeException("Rust serial agent executable not found at {$agentPath}.");
        }

        $token = SerialAgentTokens::current();
        $storagePath = storage_path();

        ChildProcess::start(
            cmd: [$agentPath],
            alias: 'serial-agent',
            cwd: dirname($agentPath),
            env: [
                'STORAGE_PATH' => $storagePath,
                'INTERNAL_TOKEN' => $token,
            ],
            persistent: true,
        );

        return true;
    }

    private function startLaravelSerialBridge(): bool
    {
        if (config('serial.driver') !== 'rust-agent') {
            return false;
        }

        $token = SerialAgentTokens::current();
        $storagePath = storage_path();

        ChildProcess::artisan(
            cmd: ['serial-agent:bridge'],
            alias: 'serial-agent-bridge',
            env: [
                'STORAGE_PATH' => $storagePath,
                'INTERNAL_TOKEN' => $token,
            ],
            persistent: true,
        );

        return true;
    }

    private function markReady(string $version): bool
    {
        $this->state->markSuccessful($version);

        return true;
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function logStep(
        string $step,
        string $status,
        ?string $detail = null,
        ?int $durationMs = null,
    ): void {
        $this->state->markStep($step, $status, $detail, $durationMs);

        Log::channel('single')->info('Native startup step', [
            'step' => $step,
            'status' => $status,
            'detail' => $detail,
            'duration_ms' => $durationMs,
        ]);
    }
}
