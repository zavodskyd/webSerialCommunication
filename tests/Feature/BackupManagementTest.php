<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Backup\NativeBackupExporter;
use App\Services\Backup\NativeBackupExportResult;
use App\Support\ApplicationBackupManager;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;

use function Pest\Laravel\mock;

test('backup settings page is accessible and navigation uses settings label', function () {
    $this->get(route('settings.backup.index'))
        ->assertOk()
        ->assertSee('Záloha a obnova');

    $this->get(route('import.devices'))
        ->assertOk()
        ->assertSee('Nastavenia')
        ->assertSee('Záloha a obnova');
});

test('json backup download includes application tables including users', function () {
    User::factory()->create([
        'name' => 'Backup Admin',
        'email' => 'backup@example.com',
    ]);

    Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    $response = $this->get(route('settings.backup.data'));

    $response->assertOk();
    $response->assertDownload('backup-data-'.now()->format('Y-m-d').'.json');

    $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['format'])->toBe('serial-communication-backup-v1');
    expect($payload['tables']['users'][0]['email'])->toBe('backup@example.com');
    expect($payload['tables']['devices'][0]['device_number'])->toBe('001');
    expect(array_keys($payload['tables']))->toBe([
        'users',
        'devices',
        'votings',
        'voting_questions',
        'voting_options',
        'voting_attendees',
        'votes',
        'vote_events',
    ]);
});

test('json restore replaces current application data including users', function () {
    User::factory()->create([
        'name' => 'Old User',
        'email' => 'old@example.com',
    ]);

    Device::query()->create([
        'device_number' => 'old-device',
        'code_a' => 'old-a',
        'code_b' => 'old-b',
        'code_c' => 'old-c',
        'code_d' => 'old-d',
        'code_e' => 'old-e',
        'code_f' => 'old-f',
        'code_ruka' => 'old-r',
    ]);

    $payload = [
        'format' => 'serial-communication-backup-v1',
        'exported_at' => now()->toIso8601String(),
        'tables' => [
            'users' => [[
                'id' => 15,
                'name' => 'Restored User',
                'email' => 'restored@example.com',
                'email_verified_at' => now()->toDateTimeString(),
                'password' => bcrypt('secret'),
                'remember_token' => null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
            'devices' => [[
                'id' => 22,
                'device_number' => 'restored-device',
                'code_a' => '2081a1',
                'code_b' => '2091b1',
                'code_c' => '20a181',
                'code_d' => '',
                'code_e' => '',
                'code_f' => '',
                'code_ruka' => '',
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
            'votings' => [],
            'voting_questions' => [],
            'voting_options' => [],
            'voting_attendees' => [],
            'votes' => [],
            'vote_events' => [],
        ],
    ];

    $upload = createUploadedJsonBackup($payload);

    try {
        $this->post(route('settings.backup.data.restore'), [
            'data_backup' => $upload,
        ])->assertRedirect()->assertSessionHas('success');
    } finally {
        @unlink($upload->getPathname());
    }

    expect(User::query()->pluck('email')->all())->toBe(['restored@example.com']);
    expect(Device::query()->pluck('device_number')->all())->toBe(['restored-device']);
});

test('database backup download returns a sqlite file response', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'backup-target-');

    file_put_contents($databasePath, 'sqlite-backup');

    mock(ApplicationBackupManager::class, function (MockInterface $mock) use ($databasePath): void {
        $mock->shouldReceive('currentDatabasePath')->once()->andReturn($databasePath);
        $mock->shouldReceive('databaseDownloadFilename')->once()->andReturn('backup-database-test.sqlite');
    });

    try {
        $this->get(route('settings.backup.database'))
            ->assertOk()
            ->assertDownload('backup-database-test.sqlite');
    } finally {
        @unlink($databasePath);
    }
});

test('database backup uses native save flow in nativephp runtime', function () {
    config(['nativephp-internal.running' => true]);

    mock(ApplicationBackupManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('currentDatabasePath')->once()->andReturn('/tmp/app-backup.sqlite');
        $mock->shouldReceive('databaseDownloadFilename')->once()->andReturn('backup-database-test.sqlite');
    });

    mock(NativeBackupExporter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exportFile')
            ->once()
            ->with(
                '/tmp/app-backup.sqlite',
                'backup-database-test.sqlite',
                'Uložiť zálohu databázy',
                'SQLite záloha',
                ['sqlite', 'db', 'sqlite3'],
            )
            ->andReturn(new NativeBackupExportResult(cancelled: false, path: '/tmp/saved-backup.sqlite'));
    });

    $this->get(route('settings.backup.database'))
        ->assertOk()
        ->assertJson([
            'cancelled' => false,
            'path' => '/tmp/saved-backup.sqlite',
        ]);
});

test('json backup uses native save flow in nativephp runtime', function () {
    config(['nativephp-internal.running' => true]);

    mock(ApplicationBackupManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exportData')->once()->andReturn([
            'format' => 'serial-communication-backup-v1',
            'exported_at' => now()->toIso8601String(),
            'tables' => [
                'users' => [],
                'devices' => [],
                'votings' => [],
                'voting_questions' => [],
                'voting_options' => [],
                'voting_attendees' => [],
                'votes' => [],
                'vote_events' => [],
            ],
        ]);
        $mock->shouldReceive('dataDownloadFilename')->once()->andReturn('backup-data-test.json');
    });

    mock(NativeBackupExporter::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exportContents')
            ->once()
            ->withArgs(function (string $contents, string $filename, string $title, string $filter, array $extensions): bool {
                expect($filename)->toBe('backup-data-test.json');
                expect($title)->toBe('Uložiť dátovú zálohu');
                expect($filter)->toBe('JSON záloha');
                expect($extensions)->toBe(['json']);
                expect(json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['format'])
                    ->toBe('serial-communication-backup-v1');

                return true;
            })
            ->andReturn(new NativeBackupExportResult(cancelled: false, path: '/tmp/saved-backup.json'));
    });

    $this->get(route('settings.backup.data'))
        ->assertOk()
        ->assertJson([
            'cancelled' => false,
            'path' => '/tmp/saved-backup.json',
        ]);
});

test('database restore delegates to backup manager and reports success', function () {
    $upload = UploadedFile::fake()->create('application-backup.sqlite', 16);

    mock(ApplicationBackupManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('restoreDatabaseFrom')
            ->once()
            ->withArgs(fn (string $path): bool => is_file($path))
            ->andReturnNull();
    });

    $this->post(route('settings.backup.database.restore'), [
        'database_backup' => $upload,
    ])->assertRedirect()->assertSessionHas('success');
});

test('database restore reports validation error for invalid sqlite backup', function () {
    $upload = UploadedFile::fake()->create('not-a-backup.sqlite', 4);

    mock(ApplicationBackupManager::class, function (MockInterface $mock): void {
        $mock->shouldReceive('restoreDatabaseFrom')
            ->once()
            ->andThrow(new InvalidArgumentException('SQLite záloha neobsahuje očakávané aplikačné tabuľky.'));
    });

    $this->from(route('settings.backup.index'))
        ->post(route('settings.backup.database.restore'), [
            'database_backup' => $upload,
        ])
        ->assertRedirect(route('settings.backup.index'))
        ->assertSessionHasErrors('database_backup');
});

/**
 * @param  array<string, mixed>  $payload
 */
function createUploadedJsonBackup(array $payload): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'backup-json-');

    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    try {
        return new UploadedFile(
            $path,
            'backup.json',
            'application/json',
            null,
            true
        );
    } catch (Throwable $exception) {
        @unlink($path);

        throw $exception;
    }
}
