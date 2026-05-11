<?php

declare(strict_types=1);

use App\Support\QomoHexFrameDecoder;

test('it decodes device numbers and button names from valid frames', function () {
    $decoder = new QomoHexFrameDecoder;

    expect($decoder->decode('2081a1'))->toBe([
        'deviceNumber' => 1,
        'buttonName' => 'A',
    ]);

    expect($decoder->decode('3486b2'))->toBe([
        'deviceNumber' => 326,
        'buttonName' => 'A',
    ]);

    expect($decoder->decode('35e5d0'))->toBe([
        'deviceNumber' => 341,
        'buttonName' => 'Ruka',
    ]);
});

test('it rejects invalid frames', function () {
    $decoder = new QomoHexFrameDecoder;

    expect($decoder->decode('2081a2'))->toBeNull();
    expect($decoder->decode('10e1f1'))->toBeNull();
    expect($decoder->decode('20f1d1'))->toBeNull();
    expect($decoder->decode('not-hex'))->toBeNull();
});
