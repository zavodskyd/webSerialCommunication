<?php

declare(strict_types=1);

use App\Support\SerialHelperTokens;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    @unlink(SerialHelperTokens::tokenPath());
    @unlink(SerialHelperTokens::portPath());
});

afterEach(function () {
    @unlink(SerialHelperTokens::tokenPath());
    @unlink(SerialHelperTokens::portPath());
});

test('it returns 503 when the helper port file is missing', function () {
    $token = SerialHelperTokens::current();

    $response = $this
        ->withHeader('X-Internal-Token', $token)
        ->postJson(route('internal.serial-control'), [
            'command' => 'open',
            'port_path' => 'COM3',
        ]);

    $response->assertStatus(503);
    $response->assertJson([
        'ok' => false,
        'error' => 'helper not running',
    ]);
});

test('it forwards a valid command to the helper', function () {
    $token = SerialHelperTokens::current();
    SerialHelperTokens::setHelperPort(9999);

    Http::fake([
        'http://127.0.0.1:9999/control' => Http::response(['ok' => true], 200),
    ]);

    $response = $this
        ->withHeader('X-Internal-Token', $token)
        ->postJson(route('internal.serial-control'), [
            'command' => 'start',
        ]);

    $response->assertOk();
    $response->assertJson(['ok' => true]);

    Http::assertSent(function (ClientRequest $request) use ($token) {
        return $request->url() === 'http://127.0.0.1:9999/control'
            && $request->method() === 'POST'
            && $request->header('X-Internal-Token')[0] === $token
            && $request['command'] === 'start';
    });
});
