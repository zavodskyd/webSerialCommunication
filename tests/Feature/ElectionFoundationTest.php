<?php

use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\Voting;

test('votings default to the standard type', function () {
    $voting = Voting::query()->create([
        'name' => 'Štandardné hlasovanie',
    ]);

    expect($voting->fresh()->voting_type)->toBe('standard');
});

test('an election owns its fixed contests and candidate lists are alphabetically ordered', function () {
    $voting = Voting::query()->create([
        'name' => 'Voľby orgánov',
        'voting_type' => 'election',
    ]);

    $election = Election::query()->create([
        'voting_id' => $voting->id,
    ]);

    $election->createDefaultContests();

    expect($voting->fresh()->election)->not->toBeNull();
    expect($election->contests()->pluck('name')->all())->toBe([
        'Predseda',
        'Predstavenstvo Hliny',
        'Predstavenstvo Solinky',
        'Predstavenstvo Vlčince',
        'Predstavenstvo Rozptyl/Staré Mesto',
        'Kontrolná komisia',
    ]);
    expect($election->contests()->pluck('seat_count')->all())->toBe([1, 2, 3, 3, 2, 7]);

    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Zuzana', 'last_name' => 'Kováčová'],
        ['first_name' => 'Adam', 'last_name' => 'Baláž'],
    ]);

    expect($contest->candidates()->get()->map(fn ($candidate) => "{$candidate->first_name} {$candidate->last_name}")->all())
        ->toBe(['Adam Baláž', 'Zuzana Kováčová']);
});

test('a device group stores its numeric ranges within an election', function () {
    $election = Election::query()->create([
        'voting_id' => Voting::query()->create([
            'name' => 'Voľby orgánov',
            'voting_type' => 'election',
        ])->id,
    ]);

    $group = DeviceGroup::query()->create([
        'election_id' => $election->id,
        'name' => 'Hliny',
        'sort_order' => 1,
    ]);

    $group->ranges()->create([
        'start_number' => 101,
        'end_number' => 125,
    ]);

    expect($election->deviceGroups()->firstOrFail()->ranges->first()->only(['start_number', 'end_number']))
        ->toBe(['start_number' => 101, 'end_number' => 125]);
});
