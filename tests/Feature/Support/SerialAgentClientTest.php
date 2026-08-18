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

test('it waits for collection to stop and every queued frame to be acknowledged', function () {
    $client = new class extends SerialAgentClient
    {
        private int $healthCheck = 0;

        public function command(string $command): array
        {
            return ['ok' => true];
        }

        public function health(): array
        {
            $responses = [
                ['ok' => true, 'collecting' => true, 'queued_frames' => 2],
                ['ok' => true, 'collecting' => false, 'queued_frames' => 1],
                ['ok' => true, 'collecting' => false, 'queued_frames' => 0],
            ];

            return $responses[$this->healthCheck++];
        }
    };

    expect($client->stopAndDrain(1000))->toMatchArray([
        'ok' => true,
        'drained' => true,
        'collecting' => false,
        'queued_frames' => 0,
    ]);
});

test('it returns a structured error when the agent port file is missing', function () {
    $response = app(SerialAgentClient::class)->command('health');

    expect($response)->toMatchArray([
        'ok' => false,
        'error' => 'agent not running',
    ]);
});
