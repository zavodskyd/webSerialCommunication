<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('legacy node helper serial frame route is not registered', function () {
    expect(Route::has('internal.serial-frame'))->toBeFalse();
});
