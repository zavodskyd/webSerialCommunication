<?php

test('auth and profile screens are not registered', function () {
    $this->get('/login')->assertNotFound();
    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/profile')->assertNotFound();
});

test('home redirects to voting management', function () {
    $this->get('/')
        ->assertRedirect('/votings');
});

test('legacy auth surfaces are gone', function () {
    $this->get('/dashboard')->assertNotFound();
    $this->get('/test')->assertNotFound();
});
