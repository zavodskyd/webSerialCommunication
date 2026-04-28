<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $defaultConnection = config('database.default');
        $database = config("database.connections.{$defaultConnection}.database");

        if ($defaultConnection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException(
                'Tests must use an in-memory SQLite database. Refusing to boot with any other database target.'
            );
        }

        DB::purge($defaultConnection);
    }
}
