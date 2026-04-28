<?php

declare(strict_types=1);

namespace App\Support;

class SerialHelperTokens
{
    private const TOKEN_FILE = 'serial-helper.token';

    private const PORT_FILE = 'serial-helper.port';

    /**
     * Read the bearer token shared between Laravel and the Node serial helper.
     * Generates one on first read so the helper, the Livewire console, and
     * the internal endpoints all converge on the same value without an
     * explicit boot step.
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
     * invalidate any in-flight helper. Atomic on a single machine: write to
     * a temp file in the same directory, then rename over the target.
     */
    public static function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));

        $path = self::tokenPath();
        self::ensureDirectory(dirname($path));

        $tempPath = tempnam(dirname($path), 'serial-helper-token-');

        if ($tempPath === false) {
            file_put_contents($path, $token);

            return $token;
        }

        file_put_contents($tempPath, $token);
        @chmod($tempPath, 0600);
        rename($tempPath, $path);

        return $token;
    }

    /**
     * Port number that the Node helper writes to disk on startup. Null when
     * the helper hasn't booted yet (e.g. dev runs without NativePHP).
     */
    public static function helperPort(): ?int
    {
        $path = self::portPath();

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        if ($contents === '' || ! ctype_digit($contents)) {
            return null;
        }

        return (int) $contents;
    }

    public static function setHelperPort(int $port): void
    {
        $path = self::portPath();
        self::ensureDirectory(dirname($path));

        file_put_contents($path, (string) $port);
    }

    public static function tokenPath(): string
    {
        return storage_path('framework/'.self::TOKEN_FILE);
    }

    public static function portPath(): string
    {
        return storage_path('framework/'.self::PORT_FILE);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }
}
