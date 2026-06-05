<?php

declare(strict_types=1);

namespace App\Support;

class SerialAgentTokens
{
    private const TOKEN_FILE = 'serial-agent.token';

    /**
     * Read the bearer token shared between Laravel and the Rust serial agent.
     * Generates one on first read so the agent, bridge, and Livewire console
     * converge on the same value without an explicit boot step.
     */
    public static function current(): string
    {
        $path = self::tokenPath();

        if (is_file($path)) {
            $existing = trim((string) @file_get_contents($path));

            if ($existing !== '') {
                return $existing;
            }
        }

        return self::regenerate();
    }

    /**
     * Force a fresh token. Used at cold start or when the operator wants to
     * invalidate any in-flight agent. Atomic on a single machine: write to
     * a temp file in the same directory, then rename over the target.
     */
    public static function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));

        $path = self::tokenPath();
        self::ensureDirectory(dirname($path));

        $tempPath = tempnam(dirname($path), 'serial-agent-token-');

        if ($tempPath === false) {
            file_put_contents($path, $token);

            return $token;
        }

        file_put_contents($tempPath, $token);
        @chmod($tempPath, 0600);
        rename($tempPath, $path);

        return $token;
    }

    public static function tokenPath(): string
    {
        return storage_path('framework/'.self::TOKEN_FILE);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }
}
