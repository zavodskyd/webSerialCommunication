<?php

use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\Voting;
use App\Services\ElectionCandidateAdmissionManager;
use App\Support\PresentationRuntimeManager;

test('a proposal is started, stopped, shown, and restarted as separate actions', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'active_device_limit' => 1]);
    $election->createDefaultContests();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $election->contests()->where('key', 'supervisory-committee')->value('id'),
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
        'response_time_seconds' => 45,
    ]);

    $manager = app(ElectionCandidateAdmissionManager::class);

    expect($admission->fresh()->status)->toBe('draft');
    expect($manager->start($admission)->status)->toBe('live');
    expect($manager->stop($admission)->status)->toBe('closed');
    expect($manager->showResults($admission)->results_visible)->toBeTrue();
    expect($manager->restart($admission)->status)->toBe('live');
    expect($admission->fresh()->results_visible)->toBeFalse();
});

test('finishing an admission automatically exposes its result', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $election->contests()->where('key', 'supervisory-committee')->value('id'),
        'first_name' => 'Jana', 'last_name' => 'Nováková', 'status' => 'live', 'opened_at' => now(),
    ]);

    $finished = app(ElectionCandidateAdmissionManager::class)->finish($admission);

    expect($finished->status)->toBe('closed');
    expect($finished->results_visible)->toBeTrue();
});

test('presentation renders the active candidate admission from the shared runtime', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $election->contests()->where('key', 'supervisory-committee')->value('id'),
        'first_name' => 'Jana', 'last_name' => 'Nováková', 'status' => 'live', 'opened_at' => now(),
    ]);
    app(PresentationRuntimeManager::class)->activate($voting, 'candidate_admission', ['admission_id' => $admission->id]);

    $this->get(route('votings.presentation', $voting))
        ->assertSuccessful()
        ->assertSee('Doplnenie kandidáta')
        ->assertSee('Jana')
        ->assertSee('Nováková');
});
