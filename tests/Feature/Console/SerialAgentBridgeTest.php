<?php

use App\Console\Commands\SerialAgentBridge;
use App\Services\SerialAgent\SerialAgentFrameHandler;
use App\Support\SerialAgentTestMonitor;

test('it mirrors frames into the test monitor before acknowledging them', function () {
    $handler = Mockery::mock(SerialAgentFrameHandler::class);
    $handler->shouldReceive('handle')->once()->with('2081a1');

    $monitor = Mockery::mock(SerialAgentTestMonitor::class);
    $monitor->shouldReceive('recordFrame')->once()->with('2081a1');

    $connection = Mockery::mock();
    $connection->shouldReceive('sendText')->once()->withArgs(function (string $payload): bool {
        $decoded = json_decode($payload, true);

        return is_array($decoded)
            && ($decoded['type'] ?? null) === 'ack'
            && ($decoded['id'] ?? null) === 'frame-1';
    });

    $command = app(SerialAgentBridge::class);
    $method = new ReflectionMethod($command, 'handleFrame');
    $method->setAccessible(true);
    $method->invoke($command, [
        'id' => 'frame-1',
        'hex' => '2081a1',
    ], $handler, $monitor, $connection);

    expect(true)->toBeTrue();
});
