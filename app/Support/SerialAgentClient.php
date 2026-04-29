<?php

declare(strict_types=1);

namespace App\Support;

use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Illuminate\Support\Str;
use Throwable;

use function Amp\Websocket\Client\connect;

class SerialAgentClient
{
    /**
     * @return array<string, mixed>
     */
    public function command(string $command): array
    {
        $port = SerialAgentFiles::port();

        if ($port === null) {
            return ['ok' => false, 'error' => 'agent not running'];
        }

        try {
            $connection = connect(
                (new WebsocketHandshake('ws://127.0.0.1:'.$port.'/ws'))
                    ->withTcpConnectTimeout(1),
                new TimeoutCancellation(2),
            );

            $connection->sendText(json_encode([
                'type' => 'hello',
                'token' => SerialHelperTokens::current(),
            ], JSON_THROW_ON_ERROR));

            $hello = $connection->receive(new TimeoutCancellation(2));
            $helloPayload = $hello?->buffer(new TimeoutCancellation(2));

            if (! is_string($helloPayload) || ! str_contains($helloPayload, '"hello_ok"')) {
                return ['ok' => false, 'error' => 'agent auth failed'];
            }

            $helloPayload = json_decode($helloPayload, true, flags: JSON_THROW_ON_ERROR);
            $latestStatus = is_array($helloPayload)
                ? $this->statusFromPayload($helloPayload)
                : [];

            $id = (string) Str::uuid();

            $connection->sendText(json_encode([
                'type' => 'command',
                'id' => $id,
                'command' => $command,
            ], JSON_THROW_ON_ERROR));

            while ($message = $connection->receive(new TimeoutCancellation(3))) {
                $payload = json_decode($message->buffer(new TimeoutCancellation(2)), true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($payload)) {
                    continue;
                }

                if (($payload['type'] ?? null) === 'status') {
                    $latestStatus = $this->statusFromPayload($payload);
                    SerialAgentStatus::put($latestStatus);

                    continue;
                }

                if (($payload['type'] ?? null) !== 'command_result') {
                    continue;
                }

                if (($payload['id'] ?? null) !== $id) {
                    continue;
                }

                if ($command === 'health') {
                    $latestStatus = array_merge($latestStatus, $this->readTrailingStatus($connection));
                }

                return array_merge(SerialAgentStatus::get(), $latestStatus, [
                    'ok' => (bool) ($payload['ok'] ?? false),
                    'error' => $payload['error'] ?? null,
                ]);
            }

            return ['ok' => false, 'error' => 'agent did not return command result'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, connected?: bool, collecting?: bool, selected_port?: ?string, queued_frames?: int, error?: string}
     */
    public function health(): array
    {
        $response = $this->command('health');

        if (($response['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error' => (string) ($response['error'] ?? 'agent not reachable'),
            ];
        }

        return array_merge(SerialAgentStatus::get(), $response, ['ok' => true]);
    }

    /**
     * @return array{connected?: bool, collecting?: bool, selected_port?: ?string, queued_frames?: int}
     */
    private function statusFromPayload(array $payload): array
    {
        $status = [];

        if (array_key_exists('connected', $payload)) {
            $status['connected'] = (bool) $payload['connected'];
        }

        if (array_key_exists('collecting', $payload)) {
            $status['collecting'] = (bool) $payload['collecting'];
        }

        if (array_key_exists('selected_port', $payload)) {
            $status['selected_port'] = is_string($payload['selected_port']) ? $payload['selected_port'] : null;
        }

        if (array_key_exists('queued_frames', $payload)) {
            $status['queued_frames'] = (int) $payload['queued_frames'];
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function readTrailingStatus(WebsocketConnection $connection): array
    {
        try {
            $message = $connection->receive(new TimeoutCancellation(1));
            $payload = $message?->buffer(new TimeoutCancellation(1));

            if (! is_string($payload)) {
                return [];
            }

            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ($payload['type'] ?? null) !== 'status') {
                return [];
            }

            $status = $this->statusFromPayload($payload);
            SerialAgentStatus::put($status);

            return $status;
        } catch (Throwable) {
            return [];
        }
    }
}
