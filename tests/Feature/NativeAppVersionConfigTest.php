<?php

test('nativephp version is configured from the environment', function () {
    expect(env('NATIVEPHP_APP_VERSION'))->not->toBeNull()
        ->and(config('nativephp.version'))->toBe(env('NATIVEPHP_APP_VERSION'));
});
