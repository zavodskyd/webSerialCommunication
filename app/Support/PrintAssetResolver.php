<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Throwable;

class PrintAssetResolver
{
    public function appCss(): string
    {
        try {
            return Vite::content('resources/css/app.css');
        } catch (Throwable) {
            return '';
        }
    }
}
