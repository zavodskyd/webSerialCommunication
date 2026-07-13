<?php

use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ElectionExportController;
use App\Http\Controllers\VoteEventsExportController;
use App\Http\Controllers\VotingExportController;
use App\Http\Controllers\VotingLogoController;
use App\Livewire\Election\ElectionCandidateAdmissionConsole;
use App\Livewire\Election\ElectionConsole;
use App\Livewire\Election\ElectionEditor;
use App\Livewire\Election\ElectionIndex;
use App\Livewire\Voting\VotingConsole;
use App\Livewire\Voting\VotingEditor;
use App\Livewire\Voting\VotingIndex;
use App\Livewire\Voting\VotingPresentation;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::redirect('/', '/votings');

Route::view('test', 'test')
    ->name('test');

Route::post('/import-devices', [DeviceController::class, 'import'])->name('import.devices');
Route::get('/import-devices', [DeviceController::class, 'index'])->name('import.devices');

Route::post('/load-external-db', [DeviceController::class, 'loadExternalDb'])->name('load.external.db');
Route::get('/import-external-db', [DeviceController::class, 'showImportForm'])->name('show.import.form');
Route::get('/devices', [DeviceController::class, 'showDevices'])->name('devices.index');
Route::get('/settings/backup', [DatabaseBackupController::class, 'index'])->name('settings.backup.index');
Route::get('/settings/backup/database', [DatabaseBackupController::class, 'downloadDatabase'])->name('settings.backup.database');
Route::get('/settings/backup/data', [DatabaseBackupController::class, 'downloadData'])->name('settings.backup.data');
Route::post('/settings/backup/database', [DatabaseBackupController::class, 'restoreDatabase'])->name('settings.backup.database.restore');
Route::post('/settings/backup/data', [DatabaseBackupController::class, 'restoreData'])->name('settings.backup.data.restore');
Route::get('/votings', VotingIndex::class)->name('votings.index');
Route::get('/votings/{voting}', VotingEditor::class)->name('votings.edit');
Route::get('/votings/{voting}/console', VotingConsole::class)->name('votings.console');
Route::get('/votings/{voting}/presentation', VotingPresentation::class)->name('votings.presentation');
Route::get('/votings/{voting}/presentation/{question}', VotingPresentation::class)->name('votings.presentation.question');
Route::get('/votings/{voting}/logo', VotingLogoController::class)->name('votings.logo');
Route::get('/votings/{voting}/exports/results', [VotingExportController::class, 'results'])->name('votings.exports.results');
Route::get('/votings/{voting}/exports/results/pdf', [VotingExportController::class, 'resultsPdf'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->name('votings.exports.results.pdf');
Route::get('/votings/{voting}/exports/pressed-options', [VotingExportController::class, 'pressedOptions'])->name('votings.exports.pressed-options');
Route::get('/votings/{voting}/exports/pressed-options/pdf', [VotingExportController::class, 'pressedOptionsPdf'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->name('votings.exports.pressed-options.pdf');
Route::get('/votings/{voting}/questions/{question}/events.csv', VoteEventsExportController::class)
    ->name('votings.questions.events-export');
Route::get('/elections', ElectionIndex::class)->name('elections.index');
Route::get('/elections/{voting}', ElectionEditor::class)->name('elections.edit');
Route::get('/elections/{voting}/candidate-admissions', ElectionCandidateAdmissionConsole::class)->name('elections.candidate-admissions');
Route::get('/elections/{voting}/console', ElectionConsole::class)->name('elections.console');
Route::get('/elections/{voting}/exports/results', [ElectionExportController::class, 'results'])->name('elections.exports.results');
Route::get('/elections/{voting}/exports/results/pdf', [ElectionExportController::class, 'resultsPdf'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->name('elections.exports.results.pdf');
Route::get('/elections/{voting}/exports/audit.csv', [ElectionExportController::class, 'audit'])->name('elections.exports.audit');
