<?php

use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/import-devices', [DeviceController::class, 'import'])->name('import.devices');
    Route::get('/import-devices', [DeviceController::class, 'index'])->name('import.devices');

    Route::post('/load-external-db', [DeviceController::class, 'loadExternalDb'])->name('load.external.db');
    Route::get('/import-external-db', [DeviceController::class, 'showImportForm'])->name('show.import.form');
});
