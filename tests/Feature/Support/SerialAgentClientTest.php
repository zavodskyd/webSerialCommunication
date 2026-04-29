<?php

declare(strict_types=1);

use App\Support\SerialAgentClient;
use App\Support\SerialAgentFiles;

beforeEach(function () {
    @unlink(SerialAgentFiles::portPath());
});

afterEach(function () {
    @unlink(SerialAgentFiles::portPath());
});

test('it returns a structured error when the agent port file is missing', function () {
    $response = app(SerialAgentClient::class)->command('health');

    expect($response)->toMatchArray([
        'ok' => false,
        'error' => 'agent not running',
    ]);
});
