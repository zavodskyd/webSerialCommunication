<?php

use App\Livewire\SerialCommunication;
use App\Models\Device;
use App\Support\SerialAgentClient;
use App\Support\SerialAgentTestMonitor;
use Livewire\Livewire;

test('it decodes a qomo frame without using stored button codes', function () {
    app(SerialAgentTestMonitor::class)->reset();

    $component = app(SerialCommunication::class);

    $result = $component->checkCode(qomoFrameFor(101, 'F'));

    expect($result)->toMatchArray([
        'found' => true,
        'deviceNumber' => '101',
        'buttonName' => 'F',
        'code' => qomoFrameFor(101, 'F'),
    ]);

    expect($component->activeCode)->toBe(qomoFrameFor(101, 'F'));
    expect($component->result)->toBe('Číslo zariadenia: 101, Stlačené tlačidlo: F ('.qomoFrameFor(101, 'F').')');
});

test('it returns a not found payload for an invalid frame', function () {
    app(SerialAgentTestMonitor::class)->reset();

    $component = app(SerialCommunication::class);

    $result = $component->checkCode('unknown-code');

    expect($result)->toMatchArray([
        'found' => false,
        'deviceNumber' => null,
        'buttonName' => null,
        'code' => 'unknown-code',
        'message' => 'Frame unknown-code sa nepodarilo dekódovať.',
    ]);
});

test('it renders serial-agent monitor data instead of lookup variables', function () {
    app(SerialAgentTestMonitor::class)->reset();

    Device::query()->create([
        'device_number' => '101',
        'code_a' => '',
        'code_b' => '',
        'code_c' => '',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    app(SerialAgentTestMonitor::class)->recordFrame(qomoFrameFor(101, 'F'));

    $client = $this->mock(SerialAgentClient::class);
    $client->shouldReceive('health')->once()->andReturn([
        'ok' => true,
        'connected' => true,
        'collecting' => true,
        'selected_port' => '/dev/cu.usbserial-test',
        'queued_frames' => 3,
    ]);

    Livewire::test(SerialCommunication::class)
        ->assertSeeText('Serial Agent')
        ->assertSeeText('Spustiť zber')
        ->assertSeeText('Číslo zariadenia')
        ->assertSeeText('Ruka')
        ->assertSeeText('/dev/cu.usbserial-test')
        ->assertSeeText('101')
        ->assertSeeText('F')
        ->assertDontSeeText('USB debug');
});

test('it starts collection through serial-agent and resets monitor counts', function () {
    $monitor = app(SerialAgentTestMonitor::class);
    $monitor->reset();
    $monitor->recordFrame(qomoFrameFor(77, 'A'));

    $client = $this->mock(SerialAgentClient::class);
    $client->shouldReceive('health')->once()->andReturn([
        'ok' => true,
        'connected' => true,
        'collecting' => false,
        'selected_port' => '/dev/cu.usbserial-test',
        'queued_frames' => 0,
    ]);
    $client->shouldReceive('command')->once()->with('start')->andReturn([
        'ok' => true,
        'connected' => true,
        'collecting' => true,
        'selected_port' => '/dev/cu.usbserial-test',
        'queued_frames' => 0,
    ]);

    Livewire::test(SerialCommunication::class)
        ->assertSet('decodedFrames', 1)
        ->call('startCollection')
        ->assertSet('collecting', true)
        ->assertSet('totalFrames', 0)
        ->assertSet('decodedFrames', 0)
        ->assertSet('invalidFrames', 0)
        ->assertSeeText('Zber bol spustený.');
});
