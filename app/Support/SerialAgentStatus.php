<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class SerialAgentStatus
{
    private const CACHE_KEY = 'serial-agent.status';

    /**
     * @param  array<string, mixed>  $status
     */
    public static function put(array $status): void
    {
        Cache::put(self::CACHE_KEY, array_merge($status, [
            'updated_at' => now()->toIso8601String(),
        ]), now()->addMinutes(5));
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $status = Cache::get(self::CACHE_KEY);

        return is_array($status) ? $status : [];
    }
}
