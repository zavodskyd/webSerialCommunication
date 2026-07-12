<?php

use App\Livewire\Election\ElectionCandidateAdmissionConsole;
use App\Models\Election;
use App\Models\Voting;
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
