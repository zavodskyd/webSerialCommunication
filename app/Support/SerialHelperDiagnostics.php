<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * In-memory + file-cached ring buffer of serial-helper events. Exists
 * because Dušan's packaged Win build can't reliably write storage/logs/
 * laravel.log — so we keep our own diagnostic trail readable from a
 * debug page in the UI without depending on the Laravel log channel.
 */
class SerialHelperDiagnostics
{
    private const CACHE_KEY = 'serial-helper-diagnostics';

    private const MAX_EVENTS = 200;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $level, string $message, array $context = []): void
    {
        $events = self::events();

        $events[] = [
            'ts' => now()->toIso8601String(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }

        Cache::store('file')->put(self::CACHE_KEY, $events, now()->addDays(7));
    }

    /**
     * @return array<int, array{ts: string, level: string, message: string, context: array<string, mixed>}>
     */
    public static function events(): array
    {
        $events = Cache::store('file')->get(self::CACHE_KEY, []);

        return is_array($events) ? $events : [];
    }

    public static function clear(): void
    {
        Cache::store('file')->forget(self::CACHE_KEY);
    }
}
