<?php

test('visitor can access import pages', function () {
    $this->get('/import-devices')
        ->assertOk();

    $this->get('/import-external-db')
        ->assertOk();
});
