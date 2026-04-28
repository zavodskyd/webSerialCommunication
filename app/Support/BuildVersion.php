<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class BuildVersion
{
    private const STAMP_FILE = 'build-version.txt';

    /**
     * Resolve the current build version. Resolution order:
     *
     * 1. `bootstrap/cache/build-version.txt` — written by `php artisan
     *    build:stamp-version` during the NativePHP prebuild hook. This is
     *    what production / packaged Electron builds carry.
     * 2. Live `git rev-parse --short HEAD` + commit timestamp — for dev mode
     *    where prebuild didn't run. Confirms which commit the dev runtime is
     *    actually running.
     * 3. `composer.lock` modification time — last-resort fallback when git
     *    isn't available (e.g., the Electron bundle was extracted somewhere
     *    without a .git directory).
     *
     * The point of all this: Dušan needs to be able to **see** which build
     * he's running so we can verify a fix is actually deployed. The string
     * is rendered into the operator console UI footer.
     */
    public static function current(): string
    {
        $stamped = self::readStampFile();

        if ($stamped !== null) {
            return $stamped;
        }

        $live = self::resolveLive();

        if ($live !== null) {
            return $live;
        }

        return self::lastResortFallback();
    }

    public static function stampFilePath(): string
    {
        return base_path('bootstrap/cache/'.self::STAMP_FILE);
    }

    private static function readStampFile(): ?string
    {
        $path = self::stampFilePath();

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return $contents === '' ? null : $contents;
    }

    private static function resolveLive(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $sha = self::shellCapture('git rev-parse --short HEAD');
        $commitTimestamp = self::shellCapture('git log -1 --format=%ct');

        if ($sha === null) {
            return null;
        }

        $base = config('nativephp.version', '1.0.0');
        $suffix = $commitTimestamp !== null
            ? '+'.$sha.'-'.$commitTimestamp
            : '+'.$sha;

        return $base.$suffix.' (dev)';
    }

    private static function lastResortFallback(): string
    {
        $base = config('nativephp.version', '1.0.0');
        $mtime = @filemtime(base_path('composer.lock'));

        if ($mtime === false) {
            return $base.' (no-stamp)';
        }

        return $base.'+mtime-'.$mtime.' (no-stamp)';
    }

    private static function shellCapture(string $command): ?string
    {
        $output = @shell_exec($command.' 2>/dev/null');

        if ($output === null || $output === false) {
            return null;
        }

        $value = trim((string) $output);

        if ($value === '' || Str::contains($value, ['fatal:', 'error:'])) {
            return null;
        }

        return $value;
    }
}
