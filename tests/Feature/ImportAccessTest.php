<?php

use App\Models\User;

test('guest can not access import pages', function () {
    $this->get('/import-devices')
        ->assertRedirect('/login');

    $this->get('/import-external-db')
        ->assertRedirect('/login');
});

test('authenticated verified user can access import pages', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get('/import-devices')
        ->assertOk();

    $this->get('/import-external-db')
        ->assertOk();
});
