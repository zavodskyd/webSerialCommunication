<?php

namespace App\Console\Commands;

use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketHandshake;
use App\Services\SerialAgent\SerialAgentFrameHandler;
use App\Support\SerialAgentFiles;
use App\Support\SerialAgentMode;
use App\Support\SerialAgentStatus;
use App\Support\SerialAgentTestMonitor;
use App\Support\SerialHelperTokens;
use Illuminate\Console\Command;
use Throwable;

use function Amp\delay;
use function Amp\Websocket\Client\connect;

class SerialAgentBridge extends Command
{
    protected $signature = 'serial-agent:bridge';

    protected $description = 'Maintain the Laravel WebSocket bridge to the local Rust serial agent';

    public function handle(SerialAgentFrameHandler $handler, SerialAgentTestMonitor $monitor): int
    {
        $this->info('Starting serial-agent bridge.');

        while (true) {
            try {
                $this->runBridge($handler, $monitor);
            } catch (Throwable $exception) {
                $this->warn('serial-agent bridge disconnected: '.$exception->getMessage());
            }

            sleep(1);
        }
    }

    private function runBridge(SerialAgentFrameHandler $handler, SerialAgentTestMonitor $monitor): void
    {
        $port = $this->waitForPort();

        $connection = connect(
            (new WebsocketHandshake('ws://127.0.0.1:'.$port.'/ws'))
                ->withTcpConnectTimeout(2),
            new TimeoutCancellation(5),
        );

        $connection->sendText(json_encode([
            'type' => 'hello',
            'token' => SerialHelperTokens::current(),
        ], JSON_THROW_ON_ERROR));

        $hello = $connection->receive(new TimeoutCancellation(5));
        $helloPayload = $hello?->buffer(new TimeoutCancellation(5));

        if (! is_string($helloPayload) || ! str_contains($helloPayload, '"hello_ok"')) {
            throw new \RuntimeException('serial-agent auth failed');
        }

        $this->info('Connected to serial-agent on port '.$port.'.');

        while ($message = $connection->receive()) {
            $payload = json_decode($message->buffer(), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                continue;
            }

            match ($payload['type'] ?? null) {
                'frame' => $this->handleFrame($payload, $handler, $monitor, $connection),
                'status' => $this->handleStatus($payload),
                default => null,
            };
        }
    }

    private function waitForPort(): int
    {
        while (($port = SerialAgentFiles::port()) === null) {
            delay(0.5);
        }

        return $port;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleFrame(array $payload, SerialAgentFrameHandler $handler, SerialAgentTestMonitor $monitor, mixed $connection): void
    {
        $id = (string) ($payload['id'] ?? '');
        $hex = (string) ($payload['hex'] ?? '');

        if ($id === '' || $hex === '') {
            return;
        }

        $monitor->recordFrame($hex);

        if (SerialAgentMode::isTest()) {
            $connection->sendText(json_encode([
                'type' => 'ack',
                'id' => $id,
            ], JSON_THROW_ON_ERROR));

            return;
        }

        $handler->handle($hex);

        $connection->sendText(json_encode([
            'type' => 'ack',
            'id' => $id,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleStatus(array $payload): void
    {
        SerialAgentStatus::put([
            'connected' => (bool) ($payload['connected'] ?? false),
            'collecting' => (bool) ($payload['collecting'] ?? false),
            'selected_port' => $payload['selected_port'] ?? null,
            'queued_frames' => (int) ($payload['queued_frames'] ?? 0),
        ]);
    }
}
