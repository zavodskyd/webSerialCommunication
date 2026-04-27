<?php

use App\Models\Device;

test('visitor can view imported devices index', function () {
    Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '20b191',
        'code_e' => '20c1e1',
        'code_f' => '20d1f1',
        'code_ruka' => '20e1c1',
    ]);

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

    $this->get(route('devices.index'))
        ->assertOk()
        ->assertSeeText('Importované zariadenia')
        ->assertSeeText('001')
        ->assertSeeText('326')
        ->assertSeeText('Nekompletné')
        ->assertSeeText('Kompletné');
});
