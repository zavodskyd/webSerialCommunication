<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\Internal\SerialControlController;
use App\Http\Controllers\Internal\SerialFrameController;
use App\Http\Controllers\VotingExportController;
use App\Http\Controllers\VotingLogoController;
use App\Livewire\Voting\VotingConsole;
use App\Livewire\Voting\VotingEditor;
use App\Livewire\Voting\VotingIndex;
use App\Livewire\Voting\VotingPresentation;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/votings');

Route::view('test', 'test')
    ->name('test');

Route::post('/import-devices', [DeviceController::class, 'import'])->name('import.devices');
Route::get('/import-devices', [DeviceController::class, 'index'])->name('import.devices');

Route::post('/load-external-db', [DeviceController::class, 'loadExternalDb'])->name('load.external.db');
Route::get('/import-external-db', [DeviceController::class, 'showImportForm'])->name('show.import.form');
Route::get('/devices', [DeviceController::class, 'showDevices'])->name('devices.index');
Route::get('/votings', VotingIndex::class)->name('votings.index');
Route::get('/votings/{voting}', VotingEditor::class)->name('votings.edit');
Route::get('/votings/{voting}/console', VotingConsole::class)->name('votings.console');
Route::get('/votings/{voting}/presentation', VotingPresentation::class)->name('votings.presentation');
Route::get('/votings/{voting}/presentation/{question}', VotingPresentation::class)->name('votings.presentation.question');
Route::get('/votings/{voting}/logo', VotingLogoController::class)->name('votings.logo');
Route::get('/votings/{voting}/exports/results', [VotingExportController::class, 'results'])->name('votings.exports.results');
Route::get('/votings/{voting}/exports/pressed-options', [VotingExportController::class, 'pressedOptions'])->name('votings.exports.pressed-options');

Route::middleware(['localhost-only', 'internal-token'])
    ->prefix('internal')
    ->group(function () {
        Route::post('/serial-frame', SerialFrameController::class)->name('internal.serial-frame');
        Route::post('/serial-control', SerialControlController::class)->name('internal.serial-control');
    });
