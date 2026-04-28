<?php

use App\Livewire\SerialCommunication;
use App\Models\Device;
use Livewire\Livewire;

test('it resolves a known device code to the correct button', function () {
    Device::query()->create([
        'device_number' => '101',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '20b191',
        'code_e' => '20c1e1',
        'code_f' => '20d1f1',
        'code_ruka' => '20e1c1',
    ]);

    $component = app(SerialCommunication::class);
    $component->mount();

    $result = $component->checkCode('20d1f1');

    expect($result)->toMatchArray([
        'found' => true,
        'deviceNumber' => '101',
        'buttonName' => 'F',
        'code' => '20d1f1',
    ]);

    expect($component->activeCode)->toBe('20d1f1');
    expect($component->result)->toBe('Názov zariadenia: 101, Stlačená hodnota: F (20d1f1)');
});

test('it returns a not found payload for an unknown code', function () {
    $component = app(SerialCommunication::class);
    $component->mount();

    $result = $component->checkCode('unknown-code');

    expect($result)->toMatchArray([
        'found' => false,
        'deviceNumber' => null,
        'buttonName' => null,
        'code' => 'unknown-code',
        'message' => 'Kód unknown-code sa nenašiel',
    ]);
});

test('it renders the compact table controls', function () {
    Device::query()->create([
        'device_number' => '101',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '20b191',
        'code_e' => '20c1e1',
        'code_f' => '20d1f1',
        'code_ruka' => '20e1c1',
    ]);

    Livewire::test(SerialCommunication::class)
        ->assertSeeText('Clear')
        ->assertSeeText('USB debug')
        ->assertSeeText('Číslo zariadenia')
        ->assertSeeText('Ruka')
        ->assertSeeText('Začať')
        ->assertSeeText('komunikáciu')
        ->assertSeeHtml('sticky top-0 z-20')
        ->assertSeeHtml('sticky top-0 z-10')
        ->assertViewHas('codeLookup', function (array $codeLookup): bool {
            return $codeLookup['20d1f1'] === [
                'deviceNumber' => '101',
                'buttonName' => 'F',
            ];
        })
        ->assertViewHas('codePrefixes', function (array $codePrefixes): bool {
            return in_array('20', $codePrefixes['oneByte'], true) &&
                in_array('20d1', $codePrefixes['twoBytes'], true);
        });
});

test('it builds a local code lookup without empty button codes', function () {
    Device::query()->create([
        'device_number' => '326',
        'code_a' => '3486b2',
        'code_b' => '3496a2',
        'code_c' => '34a692',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    $component = app(SerialCommunication::class);
    $component->mount();

    expect($component->getCodeLookup())->toMatchArray([
        '3486b2' => [
            'deviceNumber' => '326',
            'buttonName' => 'A',
        ],
        '3496a2' => [
            'deviceNumber' => '326',
            'buttonName' => 'B',
        ],
        '34a692' => [
            'deviceNumber' => '326',
            'buttonName' => 'C',
        ],
    ])->not->toHaveKey('');
});

test('it builds unique code prefixes for stream resynchronization', function () {
    Device::query()->create([
        'device_number' => '101',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '20b191',
        'code_e' => '20c1e1',
        'code_f' => '20d1f1',
        'code_ruka' => '20e1c1',
    ]);

    $component = app(SerialCommunication::class);
    $component->mount();

    expect($component->getCodePrefixes())->toMatchArray([
        'oneByte' => ['20'],
        'twoBytes' => ['2081', '2091', '20a1', '20b1', '20c1', '20d1', '20e1'],
    ]);
});
