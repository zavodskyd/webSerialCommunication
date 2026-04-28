<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Support\SerialHelperTokens;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SerialControlController extends Controller
{
    private const COMMANDS = ['open', 'init', 'start', 'stop', 'close', 'list_ports'];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'in:'.implode(',', self::COMMANDS)],
            'port_path' => ['nullable', 'string'],
        ]);

        $helperPort = SerialHelperTokens::helperPort();

        if ($helperPort === null) {
            return response()->json([
                'ok' => false,
                'error' => 'helper not running',
            ], 503);
        }

        try {
            $response = Http::baseUrl('http://127.0.0.1:'.$helperPort)
                ->withHeader('X-Internal-Token', SerialHelperTokens::current())
                ->timeout(5)
                ->post('/control', $request->all());
        } catch (ConnectionException $exception) {
            return response()->json([
                'ok' => false,
                'error' => 'helper not reachable',
            ], 503);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $payload = ['ok' => false, 'error' => 'invalid helper response'];
        }

        return response()->json($payload, $response->status());
    }
}
