<?php

use App\Livewire\Election\ElectionEditor;
use App\Livewire\Election\ElectionIndex;
use App\Livewire\Voting\VotingIndex;
use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Illuminate\Http\UploadedFile;
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

test('election management shows exports only after a round is closed', function () {
    $voting = createElectionVoting();

    Livewire::test(ElectionIndex::class)
        ->assertSee('Export výsledkov')
        ->assertSee('Export auditu')
        ->assertSeeHtml('href="'.route('elections.exports.results', $voting).'"')
        ->assertSeeHtml('href="'.route('elections.exports.audit', $voting).'"')
        ->assertSeeHtml('aria-disabled="true"');

    $voting->election->contests()->firstOrFail()->rounds()->create([
        'round_number' => 1,
        'status' => 'closed',
        'eligible_weight_total' => 1,
    ]);

    Livewire::test(ElectionIndex::class)
        ->assertSeeHtml('bg-emerald-600 text-white hover:bg-emerald-700')
        ->assertSeeHtml('bg-sky-600 text-white hover:bg-sky-700')
        ->assertDontSeeHtml('aria-disabled="true"');
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
        ->set('groupRows.0.quorum_participant_count', 120)
        ->set('groupRows.0.range.start_number', 1)
        ->set('groupRows.0.range.end_number', 20)
        ->call('addDeviceGroup')
        ->set('groupRows.1.name', 'Solinky')
        ->set('groupRows.1.quorum_participant_count', 80)
        ->set('groupRows.1.range.start_number', 21)
        ->set('groupRows.1.range.end_number', 40)
        ->call('saveDeviceGroups')
        ->assertHasNoErrors();

    expect($contest->candidates()->firstOrFail()->only(['first_name', 'last_name', 'status']))
        ->toBe(['first_name' => 'Jana', 'last_name' => 'Nováková', 'status' => 'approved']);
    expect($election->deviceGroups()->count())->toBe(2);
    expect($election->deviceGroups()->orderBy('sort_order')->pluck('quorum_participant_count')->all())->toBe([120, 80]);
});

test('manual candidate changes synchronize the latest draft round only', function () {
    $voting = createElectionVoting();
    $contest = $voting->election->contests()->firstOrFail();
    $existingCandidate = $contest->candidates()->create([
        'first_name' => 'Zuzana',
        'last_name' => 'Zelená',
        'status' => 'approved',
    ]);
    $closedRound = $contest->rounds()->create(['round_number' => 1, 'status' => 'closed']);
    $closedRound->candidates()->create([
        'election_candidate_id' => $existingCandidate->id,
        'first_name' => 'Zuzana',
        'last_name' => 'Zelená',
        'sort_order' => 1,
    ]);
    $draftRound = $contest->rounds()->create(['round_number' => 2, 'status' => 'draft']);
    $draftRound->candidates()->create([
        'election_candidate_id' => $existingCandidate->id,
        'first_name' => 'Zuzana',
        'last_name' => 'Zelená',
        'sort_order' => 1,
    ]);

    $component = Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->set("candidateDrafts.{$contest->id}.first_name", 'Anna')
        ->set("candidateDrafts.{$contest->id}.last_name", 'Adamová')
        ->call('addCandidate', $contest->id)
        ->assertHasNoErrors();

    $newCandidate = $contest->candidates()->where('last_name', 'Adamová')->firstOrFail();
    $contestRows = collect($component->get('contestRows'));
    $contestIndex = $contestRows->search(fn (array $row): bool => $row['id'] === $contest->id);
    $candidateIndex = collect($contestRows[$contestIndex]['candidates'])
        ->search(fn (array $row): bool => $row['id'] === $newCandidate->id);

    $component
        ->set("contestRows.{$contestIndex}.candidates.{$candidateIndex}.first_name", 'Beáta')
        ->set("contestRows.{$contestIndex}.candidates.{$candidateIndex}.last_name", 'Bielová')
        ->call('saveCandidate', $newCandidate->id)
        ->assertHasNoErrors();

    expect($draftRound->candidates()->orderBy('sort_order')->pluck('last_name')->all())
        ->toBe(['Bielová', 'Zelená']);

    $component->call('removeCandidate', $newCandidate->id)->assertHasNoErrors();

    expect($draftRound->candidates()->pluck('last_name')->all())->toBe(['Zelená'])
        ->and($closedRound->candidates()->pluck('last_name')->all())->toBe(['Zelená']);
});

test('device group requires a positive quorum participant count', function () {
    $voting = createElectionVoting();

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->call('addDeviceGroup')
        ->set('groupRows.0.name', 'Hliny')
        ->set('groupRows.0.quorum_participant_count', 0)
        ->set('groupRows.0.range.start_number', 1)
        ->set('groupRows.0.range.end_number', 20)
        ->call('saveDeviceGroups')
        ->assertHasErrors(['groupRows.0.quorum_participant_count' => 'min']);
});

test('device group ranges may not overlap', function () {
    $voting = createElectionVoting();

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->call('addDeviceGroup')
        ->set('groupRows.0.name', 'Hliny')
        ->set('groupRows.0.quorum_participant_count', 120)
        ->set('groupRows.0.range.start_number', 1)
        ->set('groupRows.0.range.end_number', 20)
        ->call('addDeviceGroup')
        ->set('groupRows.1.name', 'Solinky')
        ->set('groupRows.1.quorum_participant_count', 80)
        ->set('groupRows.1.range.start_number', 20)
        ->set('groupRows.1.range.end_number', 40)
        ->call('saveDeviceGroups')
        ->assertHasErrors('groupRows.1.range.start_number');
});

test('user can configure the general majority base separately from the weight helper', function () {
    $voting = createElectionVoting();
    createElectionDevice('001');
    createElectionDevice('002');
    createElectionDevice('003');

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->set('weightOneDeviceCount', 2)
        ->set('quorumParticipantCount', 120)
        ->call('fillActiveDeviceWeights')
        ->assertSet('deviceWeightRows.0.weight', '1')
        ->assertSet('deviceWeightRows.1.weight', '1')
        ->assertSet('deviceWeightRows.2.weight', '0.00')
        ->call('saveVotingWeights')
        ->assertHasNoErrors();

    expect($voting->election->refresh()->weight_one_device_count)->toBe(2)
        ->and($voting->election->quorum_participant_count)->toBe(120)
        ->and($voting->attendees()->orderBy('device_id')->pluck('weight')->all())->toBe(['1.00', '1.00', '0.00']);
});

test('user can export and import election device weights', function () {
    $voting = createElectionVoting();
    $firstDevice = createElectionDevice('001');
    $secondDevice = createElectionDevice('002');

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $firstDevice->id,
        'weight' => 5,
        'is_present' => true,
        'can_vote' => true,
    ]);

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->call('exportDeviceWeights')
        ->assertFileDownloaded('volby-organov-vahy-zariadeni.csv');

    $import = UploadedFile::fake()->createWithContent(
        'weights.csv',
        "device_number,weight\n001,3\n002,8\n999,10\n",
    );

    Livewire::test(ElectionEditor::class, ['voting' => $voting])
        ->set('deviceWeightsImport', $import)
        ->call('importDeviceWeights')
        ->assertHasNoErrors();

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $firstDevice->id)
        ->value('weight'))->toBe(3.0);

    expect((float) VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $secondDevice->id)
        ->value('weight'))->toBe(8.0);
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

function createElectionDevice(string $deviceNumber): Device
{
    return Device::query()->create([
        'device_number' => $deviceNumber,
        'code_a' => '',
        'code_b' => '',
        'code_c' => '',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);
}
