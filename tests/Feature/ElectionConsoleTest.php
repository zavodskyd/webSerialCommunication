<?php

use App\Livewire\Election\ElectionConsole;
use App\Models\Election;
use App\Models\Voting;
use Livewire\Livewire;

test('the election console links back to its editor', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->assertSee('Späť do editora')
        ->assertSee('Otvoriť prezentačné okno')
        ->assertSeeHtml('href="'.route('elections.edit', $voting).'"')
        ->assertSeeHtml('href="'.route('votings.presentation', $voting).'"')
        ->assertSeeHtml('target="_blank"');
});

test('the election console automatically selects the first candidate of a created round', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound')
        ->assertSet('candidateId', fn (?int $candidateId): bool => $candidateId === $contest->rounds()->firstOrFail()->candidates()->orderBy('sort_order')->value('id'))
        ->assertSet('remainingSeconds', 30);
});

test('the election console uses the configured response time for a created round', function () {
    $voting = Voting::query()->create([
        'name' => 'Voľby',
        'voting_type' => 'election',
        'default_response_time_seconds' => 10,
    ]);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound')
        ->assertSet('remainingSeconds', 10);

    expect($contest->rounds()->firstOrFail()->response_time_seconds)->toBe(10);
});

test('the election console advances to the next candidate after stopping the current one', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 1, 'quorum_participant_count' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $component->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $firstCandidateId = $round->candidates()->orderBy('sort_order')->value('id');
    $secondCandidateId = $round->candidates()->orderBy('sort_order')->skip(1)->value('id');

    $component->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertSet('candidateId', $secondCandidateId)
        ->assertSet('collectorEnabled', false)
        ->assertSet('timerRunning', false);

    expect($firstCandidateId)->not->toBe($secondCandidateId);
});

test('the manual result action remains available while the result is already displayed', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $component->call('createRound');
    $contest->rounds()->firstOrFail()->update(['status' => 'closed']);

    $component->set('resultsVisible', true)
        ->assertSeeHtml('wire:click="showRoundResults"')
        ->assertDontSeeHtml('wire:click="showRoundResults" disabled');
});
