<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

class SerialAgentMode
{
    public const TEST = 'test';

    public static function activateTest(): void
    {
        File::ensureDirectoryExists(dirname(SerialAgentFiles::modePath()));
        File::put(SerialAgentFiles::modePath(), self::TEST);
    }

    public static function clear(): void
    {
        File::delete(SerialAgentFiles::modePath());
    }

    public static function current(): ?string
    {
        $path = SerialAgentFiles::modePath();

        if (! File::exists($path)) {
            return null;
        }

        $mode = trim((string) File::get($path));

        return $mode !== '' ? $mode : null;
    }

    public static function isTest(): bool
    {
        return self::current() === self::TEST;
    }
}
