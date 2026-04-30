<?php

declare(strict_types=1);

use App\Models\Voting;
use App\Services\Voting\NativePdfExporter;
use App\Services\Voting\NativePdfExportResult;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\System;

test('exports a pdf using nativephp system print-to-pdf and save dialog', function () {
    $dialog = Mockery::mock(Dialog::class);
    $savePath = '/private/tmp/nativepdf-export-test';
    $resolvedPath = $savePath.'.pdf';

    @unlink($resolvedPath);

    $dialog->shouldReceive('title')->once()->with('Uložiť PDF')->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->with('Vysledky hlasovania.pdf')->andReturnSelf();
    $dialog->shouldReceive('button')->once()->with('Uložiť')->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->with('PDF', ['pdf'])->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn($savePath);

    System::shouldReceive('printToPDF')
        ->once()
        ->with('<html>Export</html>', [
            'landscape' => true,
            'pageSize' => 'A4',
            'printBackground' => true,
        ])
        ->andReturn(base64_encode('pdf-binary'));

    $result = (new NativePdfExporter($dialog))->export('<html>Export</html>', 'Vysledky hlasovania.pdf');

    expect($result)->toBeInstanceOf(NativePdfExportResult::class);
    expect($result->cancelled)->toBeFalse();
    expect($result->path)->toBe($resolvedPath);
    expect(file_get_contents($resolvedPath))->toBe('pdf-binary');

    @unlink($resolvedPath);
});

test('results pdf route returns conflict outside nativephp runtime', function () {
    config(['nativephp-internal.running' => false]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'auto_show_results' => true,
    ]);

    $this->get(route('votings.exports.results.pdf', $voting))
        ->assertConflict()
        ->assertJson([
            'message' => 'PDF export je dostupný len v NativePHP desktop aplikácii.',
        ]);
});
