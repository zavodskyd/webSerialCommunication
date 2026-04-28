<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin Laravel-side client for the Node serial helper running in the
 * Electron main process. The Livewire VotingConsole component uses this to
 * issue control commands (open/init/start/stop/close/list_ports) when the
 * SERIAL_DRIVER=node-helper flag is set.
 *
 * This is the SAME helper that SerialControlController forwards to. The
 * Livewire path bypasses the controller because we're already inside the
 * Laravel process — there's no reason to round-trip through HTTP twice.
 */
class SerialHelperClient
{
    /**
     * Issue a command to the helper. Returns the parsed JSON response, or a
     * `{ok: false, error: ...}` shape if the helper is unreachable / down.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function call(string $command, array $extra = []): array
    {
        $port = SerialHelperTokens::helperPort();

        if ($port === null) {
            return ['ok' => false, 'error' => 'helper not running'];
        }

        $body = array_merge(['command' => $command], $extra);

        try {
            $response = Http::baseUrl('http://127.0.0.1:'.$port)
                ->withHeader('X-Internal-Token', SerialHelperTokens::current())
                ->timeout(5)
                ->acceptJson()
                ->asJson()
                ->post('/control', $body);
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'helper not reachable'];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : ['ok' => false, 'error' => 'invalid helper response'];
    }
}
