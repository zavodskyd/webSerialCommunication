<?php

declare(strict_types=1);

use App\Models\Voting;
use App\Services\Voting\NativePdfExporter;
use App\Services\Voting\NativePdfExportResult;
use App\Support\PrintAssetResolver;
use GuzzleHttp\Psr7\Response;
use Native\Desktop\Client\Client;
use Native\Desktop\Dialog;

test('exports a pdf using nativephp system print-to-pdf and save dialog', function () {
    $dialog = Mockery::mock(Dialog::class);
    $client = Mockery::mock(Client::class);
    $savePath = '/private/tmp/nativepdf-export-test';
    $resolvedPath = $savePath.'.pdf';

    @unlink($resolvedPath);

    $dialog->shouldReceive('title')->once()->with('Uložiť PDF')->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->with('Vysledky hlasovania.pdf')->andReturnSelf();
    $dialog->shouldReceive('button')->once()->with('Uložiť')->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->with('Dokument vo formáte PDF', ['pdf'])->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn($savePath);

    $client->shouldReceive('post')
        ->once()
        ->withArgs(function (string $endpoint, array $payload): bool {
            expect($endpoint)->toBe('system/print-to-pdf');
            expect($payload['settings'])->toBe([
                'landscape' => true,
                'pageSize' => 'A4',
                'printBackground' => true,
            ]);
            expect($payload['htmlPath'])->toBeString();
            expect($payload)->not->toHaveKey('html');
            expect(file_exists($payload['htmlPath']))->toBeTrue();
            expect(file_get_contents($payload['htmlPath']))->toBe('<html>Export</html>');

            return true;
        })
        ->andReturn(new Illuminate\Http\Client\Response(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['result' => base64_encode('pdf-binary')], JSON_THROW_ON_ERROR),
        )));

    $result = (new NativePdfExporter($dialog, $client))->export('<html>Export</html>', 'Vysledky hlasovania.pdf');

    expect($result)->toBeInstanceOf(NativePdfExportResult::class);
    expect($result->cancelled)->toBeFalse();
    expect($result->path)->toBe($resolvedPath);
    expect(file_get_contents($resolvedPath))->toBe('pdf-binary');
    expect(glob(storage_path('framework/native-pdf-exports/*.html')))->toBe([]);

    @unlink($resolvedPath);
});

test('throws a runtime exception with nativephp api error details when pdf generation fails', function () {
    $dialog = Mockery::mock(Dialog::class);
    $client = Mockery::mock(Client::class);

    $dialog->shouldReceive('title')->once()->andReturnSelf();
    $dialog->shouldReceive('defaultPath')->once()->andReturnSelf();
    $dialog->shouldReceive('button')->once()->andReturnSelf();
    $dialog->shouldReceive('filter')->once()->andReturnSelf();
    $dialog->shouldReceive('save')->once()->andReturn('/private/tmp/nativepdf-export-test');

    $client->shouldReceive('post')
        ->once()
        ->withArgs(function (string $endpoint, array $payload): bool {
            expect($endpoint)->toBe('system/print-to-pdf');
            expect($payload['htmlPath'])->toBeString();
            expect(file_exists($payload['htmlPath']))->toBeTrue();

            return true;
        })
        ->andReturn(new Illuminate\Http\Client\Response(new Response(
            400,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Print job failed in Electron'], JSON_THROW_ON_ERROR),
        )));

    expect(fn () => (new NativePdfExporter($dialog, $client))->export('<html>Export</html>', 'Vysledky hlasovania.pdf'))
        ->toThrow(RuntimeException::class, 'NativePHP PDF export zlyhal: Print job failed in Electron');

    expect(glob(storage_path('framework/native-pdf-exports/*.html')))->toBe([]);
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

test('print asset resolver returns compiled app css content when available', function () {
    $css = app(PrintAssetResolver::class)->appCss();

    expect($css)->toBeString();
    expect($css)->not->toBe('');
    expect($css)->toContain('box-sizing:border-box');
});
