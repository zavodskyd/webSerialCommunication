<?php

declare(strict_types=1);

use App\Services\Backup\NativeBackupExporter;
use App\Services\Backup\NativeBackupExportResult;
use Native\Desktop\Dialog;

test('exports an sqlite backup using native save dialog', function () {
    $dialog = Mockery::mock(Dialog::class);
    $sourcePath = tempnam(sys_get_temp_dir(), 'native-backup-source-');
    $savePath = '/private/tmp/native-backup-export-test';
    $resolvedPath = $savePath.'.sqlite';

    file_put_contents($sourcePath, 'sqlite-backup');
    @unlink($resolvedPath);

    $dialog->shouldReceive('title')->once()->with('Uložiť zálohu databázy')->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->with('backup-database.sqlite')->andReturnSelf();
    $dialog->shouldReceive('button')->once()->with('Uložiť')->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->with('SQLite záloha', ['sqlite', 'db', 'sqlite3'])->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn($savePath);

    $result = (new NativeBackupExporter($dialog))->exportFile(
        sourcePath: $sourcePath,
        filename: 'backup-database.sqlite',
        dialogTitle: 'Uložiť zálohu databázy',
        filterName: 'SQLite záloha',
        extensions: ['sqlite', 'db', 'sqlite3'],
    );

    expect($result)->toBeInstanceOf(NativeBackupExportResult::class);
    expect($result->cancelled)->toBeFalse();
    expect($result->path)->toBe($resolvedPath);
    expect(file_get_contents($resolvedPath))->toBe('sqlite-backup');

    @unlink($sourcePath);
    @unlink($resolvedPath);
});

test('exports json contents using native save dialog', function () {
    $dialog = Mockery::mock(Dialog::class);
    $savePath = '/private/tmp/native-backup-json-test';
    $resolvedPath = $savePath.'.json';

    @unlink($resolvedPath);

    $dialog->shouldReceive('title')->once()->with('Uložiť dátovú zálohu')->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->with('backup-data.json')->andReturnSelf();
    $dialog->shouldReceive('button')->once()->with('Uložiť')->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->with('JSON záloha', ['json'])->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn($savePath);

    $result = (new NativeBackupExporter($dialog))->exportContents(
        contents: '{"ok":true}',
        filename: 'backup-data.json',
        dialogTitle: 'Uložiť dátovú zálohu',
        filterName: 'JSON záloha',
        extensions: ['json'],
    );

    expect($result->cancelled)->toBeFalse();
    expect($result->path)->toBe($resolvedPath);
    expect(file_get_contents($resolvedPath))->toBe('{"ok":true}');

    @unlink($resolvedPath);
});

test('returns cancelled result when native save dialog is dismissed', function () {
    $dialog = Mockery::mock(Dialog::class);

    $dialog->shouldReceive('title')->once()->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->andReturnSelf();
    $dialog->shouldReceive('button')->once()->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn(null);

    $result = (new NativeBackupExporter($dialog))->exportContents(
        contents: '{"ok":true}',
        filename: 'backup-data.json',
        dialogTitle: 'Uložiť dátovú zálohu',
        filterName: 'JSON záloha',
        extensions: ['json'],
    );

    expect($result->cancelled)->toBeTrue();
    expect($result->path)->toBeNull();
});
