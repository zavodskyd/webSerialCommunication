<?php

it('redirects home to voting management', function () {
    $response = $this->get('/');

    $response->assertRedirect('/votings');
});
