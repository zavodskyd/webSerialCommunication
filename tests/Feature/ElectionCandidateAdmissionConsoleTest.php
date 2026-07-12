<?php

use App\Livewire\Election\ElectionCandidateAdmissionConsole;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\Voting;
use App\Support\PresentationRuntimeManager;
use Livewire\Livewire;

test('user can open a localized candidate admission', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $group = $election->deviceGroups()->create(['name' => 'Hliny', 'sort_order' => 1]);

    Livewire::test(ElectionCandidateAdmissionConsole::class, ['voting' => $voting])
        ->set('firstName', 'Jana')->set('lastName', 'Nováková')
        ->set('deviceGroupId', $group->id)
        ->call('createAndOpenAdmission')
        ->assertHasNoErrors();

    expect($election->fresh()->voting->id)->toBe($voting->id);
});

test('operator can resolve the active admission and return the presentation runtime to idle', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'supervisory-committee')->firstOrFail();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
        'status' => 'closed',
        'opened_at' => now(),
        'results_visible' => true,
    ]);
    app(PresentationRuntimeManager::class)->activate($voting, 'candidate_admission', ['admission_id' => $admission->id]);

    Livewire::test(ElectionCandidateAdmissionConsole::class, ['voting' => $voting])
        ->call('resolveAdmission', $admission->id)
        ->assertHasNoErrors();

    expect($admission->fresh()->status)->toBe('rejected');
    expect(app(PresentationRuntimeManager::class)->current()->content_type)->toBe('none');
});
