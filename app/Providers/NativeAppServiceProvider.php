<?php

namespace App\Providers;

use App\Support\StartupCoordinator;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Alert;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Window;
use Throwable;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Force log channel to 'single' on NativePHP runtime — the default
        // 'stack'/'daily' setup can fail silently inside a packaged .exe
        // (no laravel.log appears in Dušan's Win build). 'single' is the
        // simplest path: one append-only file with a deterministic name.
        config(['logging.default' => 'single']);

        Log::info('NativeAppServiceProvider::boot', [
            'serial_driver' => config('serial.driver'),
            'base_path' => base_path(),
            'storage_path' => storage_path(),
            'app_url' => config('app.url'),
        ]);

        try {
            app(StartupCoordinator::class)->run();
        } catch (Throwable $exception) {
            $this->handleStartupFailure($exception);

            return;
        }

        Window::open()
            ->maximized();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }

    private function handleStartupFailure(Throwable $exception): void
    {
        Log::critical('Native application startup failed', [
            'exception' => $exception,
            'message' => $exception->getMessage(),
        ]);

        try {
            $selectedButton = Alert::new()
                ->title('Aktualizáciu databázy sa nepodarilo dokončiť')
                ->detail('Aplikácia nemôže bezpečne pokračovať. Podrobnosti sú uložené v aplikačnom logu.')
                ->buttons(['Skúsiť znova', 'Ukončiť aplikáciu'])
                ->defaultId(0)
                ->cancelId(1)
                ->show('Skontrolujte prístup k databáze a skúste aktualizáciu znova.');

            if ($selectedButton === 0) {
                App::relaunch();

                return;
            }
        } catch (Throwable $alertException) {
            Log::critical('Native startup recovery dialog failed', [
                'exception' => $alertException,
                'message' => $alertException->getMessage(),
            ]);
        }

        App::quit();
    }
}
