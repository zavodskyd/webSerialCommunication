<?php

use App\Livewire\Election\ElectionEditor;
use App\Livewire\Election\ElectionIndex;
use App\Livewire\Voting\VotingIndex;
use App\Models\Election;
use App\Models\Voting;
use Livewire\Livewire;

test('user can create an election with the fixed contests', function () {
    Livewire::test(ElectionIndex::class)
        ->set('name', 'Voľby orgánov 2026')
        ->call('createElection')
        ->assertRedirect();

    $voting = Voting::query()->where('name', 'Voľby orgánov 2026')->firstOrFail();

    expect($voting->voting_type)->toBe('election');
    expect($voting->election)->toBeInstanceOf(Election::class);
    expect($voting->election->contests()->pluck('key')->all())->toBe([
        'chairperson',
        'board-hliny',
        'board-solinky',
        'board-vlcince',
        'board-rozptyl-stare-mesto',
        'supervisory-committee',
    ]);
});

test('election management routes render their Livewire components', function () {
    $voting = createElectionVoting();

    $this->get(route('elections.index'))
        ->assertOk()
        ->assertSeeLivewire(ElectionIndex::class)
        ->assertSeeText('Správa volieb');

    $this->get(route('elections.edit', $voting))
        ->assertOk()
        ->assertSeeLivewire(ElectionEditor::class)
        ->assertSeeText('Kandidátky súťaží');
});

test('standard voting list excludes elections', function () {
    Voting::query()->create(['name' => 'Štandardné hlasovanie']);
    createElectionVoting();

    Livewire::test(VotingIndex::class)
        ->assertSee('Štandardné hlasovanie')
        ->assertDontSee('Voľby orgánov');
});

test('user can manage direct candidates and non-overlapping device groups', function () {
    $voting = createElectionVoting();
    $election = $voting->election;
    $contest = $election->contests()->firstOrFail();

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->set("candidateDrafts.{$contest->id}.first_name", 'Jana')
        ->set("candidateDrafts.{$contest->id}.last_name", 'Nováková')
        ->call('addCandidate', $contest->id)
        ->assertHasNoErrors()
        ->call('addDeviceGroup')
        ->set('groupRows.0.name', 'Hliny')
        ->set('groupRows.0.range.start_number', 1)
        ->set('groupRows.0.range.end_number', 20)
        ->call('addDeviceGroup')
        ->set('groupRows.1.name', 'Solinky')
        ->set('groupRows.1.range.start_number', 21)
        ->set('groupRows.1.range.end_number', 40)
        ->call('saveDeviceGroups')
        ->assertHasNoErrors();

    expect($contest->candidates()->firstOrFail()->only(['first_name', 'last_name', 'status']))
        ->toBe(['first_name' => 'Jana', 'last_name' => 'Nováková', 'status' => 'approved']);
    expect($election->deviceGroups()->count())->toBe(2);
});

test('device group ranges may not overlap', function () {
    $voting = createElectionVoting();

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->call('addDeviceGroup')
        ->set('groupRows.0.name', 'Hliny')
        ->set('groupRows.0.range.start_number', 1)
        ->set('groupRows.0.range.end_number', 20)
        ->call('addDeviceGroup')
        ->set('groupRows.1.name', 'Solinky')
        ->set('groupRows.1.range.start_number', 20)
        ->set('groupRows.1.range.end_number', 40)
        ->call('saveDeviceGroups')
        ->assertHasErrors('groupRows.1.range.start_number');
});

function createElectionVoting(): Voting
{
    $voting = Voting::query()->create([
        'name' => 'Voľby orgánov',
        'voting_type' => 'election',
    ]);

    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();

    return $voting;
}
