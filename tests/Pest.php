<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function qomoFrameFor(int $deviceNumber, string $buttonName): string
{
    $buttonPrefixes = [
        'A' => 0x80,
        'B' => 0x90,
        'C' => 0xA0,
        'D' => 0xB0,
        'E' => 0xC0,
        'F' => 0xD0,
        'Ruka' => 0xE0,
    ];

    $byte1 = 0x20 + intdiv($deviceNumber, 16);
    $byte2 = $buttonPrefixes[$buttonName] | ($deviceNumber % 16);
    $byte3 = $byte1 ^ $byte2;

    return sprintf('%02x%02x%02x', $byte1, $byte2, $byte3);
}
