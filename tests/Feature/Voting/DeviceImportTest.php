<?php

use App\Models\Device;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('csv import creates devices and is idempotent on re-import', function () {
    $csv = <<<'CSV'
device_number,code_a,code_b,code_c,code_d,code_e,code_f,code_ruka
001,2081a1,2091b1,20a181,20b121,20c131,20d141,20e151
002,2082a2,2092b2,20a282,20b222,20c232,20d242,20e252
003,2083a3,2093b3,20a383,20b323,20c333,20d343,20e353
CSV;

    $file = UploadedFile::fake()->createWithContent('devices.csv', $csv);

    $response = $this->post(route('import.devices'), ['csv_file' => $file]);

    $response->assertOk();
    expect(Device::query()->count())->toBe(3);
    expect(Device::query()->where('device_number', '002')->first()->code_b)->toBe('2092b2');

    $reimport = UploadedFile::fake()->createWithContent('devices.csv', $csv);
    $this->post(route('import.devices'), ['csv_file' => $reimport])->assertOk();

    expect(Device::query()->count())->toBe(3);
});

test('csv import keeps device listings in numeric order', function () {
    $csv = <<<'CSV'
device_number,code_a,code_b,code_c,code_d,code_e,code_f,code_ruka
1,2081a1,2091b1,20a181,20b121,20c131,20d141,20e151
10,2082a2,2092b2,20a282,20b222,20c232,20d242,20e252
2,2083a3,2093b3,20a383,20b323,20c333,20d343,20e353
CSV;

    $file = UploadedFile::fake()->createWithContent('devices.csv', $csv);

    $this->post(route('import.devices'), ['csv_file' => $file])
        ->assertOk();

    $response = $this->get(route('devices.index'))
        ->assertOk();

    expect(deviceNumbersFromListing($response->getContent()))
        ->toBe(['1', '2', '10']);
});

test('csv import rejects a non-csv file with 422', function () {
    $file = UploadedFile::fake()->create('not-a-csv.png', 10, 'image/png');

    $this->post(route('import.devices'), ['csv_file' => $file])
        ->assertStatus(422);

    expect(Device::query()->count())->toBe(0);
});

test('external sqlite import maps SKDP_ParentZariadenie rows onto devices', function () {
    Storage::disk('local')->makeDirectory('temp');

    $sourcePath = tempnam(sys_get_temp_dir(), 'qomo_test_').'.sqlite';
    $pdo = new PDO('sqlite:'.$sourcePath);
    $pdo->exec(
        'CREATE TABLE SKDP_ParentZariadenie (
            UniqueId TEXT,
            A_Code TEXT,
            B_Code TEXT,
            C_Code TEXT,
            D_Code TEXT,
            E_Code TEXT,
            F_Code TEXT,
            Ruka_Code TEXT
        )',
    );
    $insert = $pdo->prepare(
        'INSERT INTO SKDP_ParentZariadenie VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $insert->execute(['001', '2081a1', '2091b1', '20a181', '20b121', '20c131', '20d141', '20e151']);
    $insert->execute(['002', '2082a2', '2092b2', '20a282', '20b222', '20c232', '20d242', '20e252']);

    $file = new UploadedFile(
        $sourcePath,
        'devices.sqlite',
        'application/octet-stream',
        null,
        true,
    );

    $this->post(route('load.external.db'), ['db_file' => $file])
        ->assertSessionHas('success');

    expect(Device::query()->count())->toBe(2);

    $device = Device::query()->where('device_number', '001')->first();

    expect($device)->not->toBeNull();
    expect($device->code_a)->toBe('2081a1');
    expect($device->code_ruka)->toBe('20e151');
});

test('external sqlite import keeps device listings in numeric order', function () {
    Storage::disk('local')->makeDirectory('temp');

    $sourcePath = tempnam(sys_get_temp_dir(), 'qomo_test_').'.sqlite';
    $pdo = new PDO('sqlite:'.$sourcePath);
    $pdo->exec(
        'CREATE TABLE SKDP_ParentZariadenie (
            UniqueId TEXT,
            A_Code TEXT,
            B_Code TEXT,
            C_Code TEXT,
            D_Code TEXT,
            E_Code TEXT,
            F_Code TEXT,
            Ruka_Code TEXT
        )',
    );
    $insert = $pdo->prepare(
        'INSERT INTO SKDP_ParentZariadenie VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $insert->execute(['1', '2081a1', '2091b1', '20a181', '20b121', '20c131', '20d141', '20e151']);
    $insert->execute(['10', '2082a2', '2092b2', '20a282', '20b222', '20c232', '20d242', '20e252']);
    $insert->execute(['2', '2083a3', '2093b3', '20a383', '20b323', '20c333', '20d343', '20e353']);

    $file = new UploadedFile(
        $sourcePath,
        'devices.sqlite',
        'application/octet-stream',
        null,
        true,
    );

    $this->post(route('load.external.db'), ['db_file' => $file])
        ->assertSessionHas('success');

    $response = $this->get(route('devices.index'))
        ->assertOk();

    expect(deviceNumbersFromListing($response->getContent()))
        ->toBe(['1', '2', '10']);
});

test('external sqlite import rejects a non-sqlite file with a validation error', function () {
    $file = UploadedFile::fake()->create('not-a-db.png', 10, 'image/png');

    $this->post(route('load.external.db'), ['db_file' => $file])
        ->assertSessionHasErrors('db_file');

    expect(Device::query()->count())->toBe(0);
});

function deviceNumbersFromListing(string $content): array
{
    preg_match_all(
        '/<span class="font-semibold text-stone-900 dark:text-white">([^<]+)<\/span>/',
        $content,
        $matches,
    );

    return $matches[1];
}
