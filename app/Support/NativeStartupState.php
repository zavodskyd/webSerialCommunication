<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;

class NativeStartupState
{
    public const STATE_FILE = 'native-startup-state.json';

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array{
     *     last_started_version?: string|null,
     *     last_successful_started_at?: string|null,
     *     last_failed_step?: string|null,
     *     last_failed_message?: string|null,
     *     current_step?: string|null,
     *     current_status?: string|null,
     *     current_detail?: string|null,
     *     current_duration_ms?: int|null
     * }
     */
    public function load(): array
    {
        if (! $this->files->exists($this->path())) {
            return [];
        }

        $decoded = json_decode((string) $this->files->get($this->path()), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function lastStartedVersion(): ?string
    {
        $value = $this->load()['last_started_version'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function markStep(
        string $step,
        string $status,
        ?string $detail = null,
        ?int $durationMs = null,
    ): void {
        $this->write([
            ...$this->load(),
            'current_step' => $step,
            'current_status' => $status,
            'current_detail' => $detail,
            'current_duration_ms' => $durationMs,
        ]);
    }

    public function markSuccessful(string $version): void
    {
        $this->write([
            ...$this->load(),
            'last_started_version' => $version,
            'last_successful_started_at' => now()->toIso8601String(),
            'last_failed_step' => null,
            'last_failed_message' => null,
            'current_step' => 'mark-startup-ready',
            'current_status' => 'ok',
            'current_detail' => null,
            'current_duration_ms' => null,
        ]);
    }

    public function markFailed(string $step, string $message): void
    {
        $this->write([
            ...$this->load(),
            'last_failed_step' => $step,
            'last_failed_message' => $message,
            'current_step' => $step,
            'current_status' => 'failed',
            'current_detail' => $message,
        ]);
    }

    public function path(): string
    {
        return storage_path('framework/'.self::STATE_FILE);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path()));

        $this->files->put(
            $this->path(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }
}
