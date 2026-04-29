<?php

declare(strict_types=1);

namespace App\Support;

class SerialAgentFiles
{
    private const PORT_FILE = 'serial-agent.port';

    public static function portPath(): string
    {
        return storage_path('framework/'.self::PORT_FILE);
    }

    public static function port(): ?int
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

    public static function executablePath(): string
    {
        $configuredPath = config('serial.agent_executable_path');

        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        $extrasPath = getenv('NATIVEPHP_EXTRAS_PATH') ?: base_path('extras');

        return $extrasPath.DIRECTORY_SEPARATOR.'serial-agent'.DIRECTORY_SEPARATOR.'serial-agent.exe';
    }
}
