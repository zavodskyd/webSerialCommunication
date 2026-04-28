<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SerialHelperClient;
use App\Support\SerialHelperDiagnostics;
use App\Support\SerialHelperTokens;
use Illuminate\Contracts\View\View;

/**
 * One-page diagnostic dashboard for the Node serial helper. Exposed at
 * /debug/serial-helper so Dušan can see why the helper isn't starting
 * without having to find storage/logs/laravel.log inside the packaged
 * .exe (which on Win sometimes doesn't get written at all).
 */
class SerialHelperDebugController extends Controller
{
    public function __invoke(): View
    {
        $candidates = [
            base_path('nativephp/electron/serial-helper.js'),
            base_path('../app/serial-helper.js'),
            base_path('../../app/serial-helper.js'),
            base_path('serial-helper.js'),
        ];

        $candidateProbe = array_map(fn (string $p) => [
            'path' => $p,
            'exists' => is_file($p),
            'size' => is_file($p) ? filesize($p) : null,
        ], $candidates);

        $tokenPath = SerialHelperTokens::tokenPath();
        $portPath = SerialHelperTokens::portPath();
        $logPath = storage_path('logs/serial-helper.log');
        $queuePath = storage_path('framework/serial-helper-queue.jsonl');
        $laravelLog = storage_path('logs/laravel.log');

        $health = SerialHelperClient::health();
        $listPorts = SerialHelperClient::call('list_ports');

        return view('debug.serial-helper', [
            'driver' => config('serial.driver'),
            'basePath' => base_path(),
            'storagePath' => storage_path(),
            'appUrl' => config('app.url'),
            'logChannel' => config('logging.default'),
            'candidates' => $candidateProbe,
            'tokenInfo' => [
                'path' => $tokenPath,
                'exists' => is_file($tokenPath),
                'size' => is_file($tokenPath) ? filesize($tokenPath) : null,
            ],
            'portInfo' => [
                'path' => $portPath,
                'exists' => is_file($portPath),
                'value' => SerialHelperTokens::helperPort(),
            ],
            'helperLogInfo' => [
                'path' => $logPath,
                'exists' => is_file($logPath),
                'size' => is_file($logPath) ? filesize($logPath) : null,
                'tail' => is_file($logPath) ? self::tail($logPath, 30) : null,
            ],
            'queueInfo' => [
                'path' => $queuePath,
                'exists' => is_file($queuePath),
                'size' => is_file($queuePath) ? filesize($queuePath) : null,
            ],
            'laravelLogInfo' => [
                'path' => $laravelLog,
                'exists' => is_file($laravelLog),
                'size' => is_file($laravelLog) ? filesize($laravelLog) : null,
                'tail' => is_file($laravelLog) ? self::tail($laravelLog, 50) : null,
            ],
            'health' => $health,
            'listPorts' => $listPorts,
            'diagnostics' => array_reverse(SerialHelperDiagnostics::events()),
        ]);
    }

    private static function tail(string $path, int $lines): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return '';
        }

        $rows = array_slice(explode("\n", rtrim($contents, "\n")), -$lines);

        return implode("\n", $rows);
    }
}
