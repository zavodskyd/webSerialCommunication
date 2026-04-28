<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BuildVersion;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('build:stamp-version', 'Write the current build version into bootstrap/cache for runtime display.')]
class StampBuildVersion extends Command
{
    public function handle(): int
    {
        $base = (string) config('nativephp.version', '1.0.0');
        $sha = $this->git('git rev-parse --short HEAD');
        $commitTimestamp = $this->git('git log -1 --format=%ct');
        $buildTimestamp = (string) time();

        $stamp = $sha !== null
            ? sprintf('%s+%s-%s.%s', $base, $sha, $commitTimestamp ?? '0', $buildTimestamp)
            : sprintf('%s+build-%s', $base, $buildTimestamp);

        $path = BuildVersion::stampFilePath();

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            $this->error('Could not create directory: '.$directory);

            return self::FAILURE;
        }

        if (file_put_contents($path, $stamp) === false) {
            $this->error('Could not write build version to: '.$path);

            return self::FAILURE;
        }

        $this->info('Build version stamped: '.$stamp);

        return self::SUCCESS;
    }

    private function git(string $command): ?string
    {
        $output = @shell_exec($command.' 2>/dev/null');

        if ($output === null || $output === false) {
            return null;
        }

        $value = trim((string) $output);

        return $value === '' ? null : $value;
    }
}
