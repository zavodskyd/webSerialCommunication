<?php

it('redirects home to voting management', function () {
    $response = $this->get('/');

    $response->assertRedirect('/votings');
});

it('uses the Hlasovanie name and Konseza application logo', function () {
    config()->set('app.name', 'Hlasovanie');

    $this->blade('<x-application-logo class="h-9" />')
        ->assertSee('src="'.asset('images/konseza-icon.svg').'"', false)
        ->assertSee('alt="Hlasovanie"', false)
        ->assertSee('object-contain', false);

    expect(public_path('images/konseza-icon.svg'))->toBeFile();
});
