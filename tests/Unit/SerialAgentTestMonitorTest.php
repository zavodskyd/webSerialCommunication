<?php

use App\Support\SerialAgentTestMonitor;
use Tests\TestCase;

uses(TestCase::class);

test('it records decoded frames into counts and recent activity', function () {
    $monitor = app(SerialAgentTestMonitor::class);
    $monitor->reset();

    $monitor->recordFrame(qomoFrameFor(341, 'C'));

    expect($monitor->snapshot())->toMatchArray([
        'lastHex' => qomoFrameFor(341, 'C'),
        'lastDeviceNumber' => '341',
        'lastButtonName' => 'C',
        'totalFrames' => 1,
        'decodedFrames' => 1,
        'invalidFrames' => 0,
        'deviceButtonCounts' => [
            '341' => [
                'A' => 0,
                'B' => 0,
                'C' => 1,
                'D' => 0,
                'E' => 0,
                'F' => 0,
                'Ruka' => 0,
            ],
        ],
    ]);

    expect($monitor->snapshot()['recentFrames'][0])->toMatchArray([
        'hex' => qomoFrameFor(341, 'C'),
        'deviceNumber' => '341',
        'buttonName' => 'C',
        'valid' => true,
    ]);
});

test('it records invalid frames separately and can be reset', function () {
    $monitor = app(SerialAgentTestMonitor::class);
    $monitor->reset();

    $monitor->recordFrame('invalid');

    expect($monitor->snapshot())->toMatchArray([
        'lastHex' => 'invalid',
        'lastDeviceNumber' => null,
        'lastButtonName' => null,
        'totalFrames' => 1,
        'decodedFrames' => 0,
        'invalidFrames' => 1,
    ]);

    $monitor->reset();

    expect($monitor->snapshot())->toMatchArray([
        'lastHex' => null,
        'lastDeviceNumber' => null,
        'lastButtonName' => null,
        'totalFrames' => 0,
        'decodedFrames' => 0,
        'invalidFrames' => 0,
        'deviceButtonCounts' => [],
        'recentFrames' => [],
    ]);
});
