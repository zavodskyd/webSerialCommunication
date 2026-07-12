<?php

use App\Models\PresentationRuntime;
use App\Models\Voting;
use App\Support\PresentationRuntimeManager;

test('the shared runtime switches its active presentation context', function () {
    $manager = app(PresentationRuntimeManager::class);
    $standardVoting = Voting::query()->create(['name' => 'Štandardné hlasovanie']);
    $electionVoting = Voting::query()->create([
        'name' => 'Voľby orgánov',
        'voting_type' => 'election',
    ]);

    $manager->activate($standardVoting, 'standard_question', ['question_id' => 10]);
    $runtime = $manager->activate($electionVoting, 'candidate_admission', [
        'admission_id' => 42,
        'device_group_id' => 3,
    ]);

    expect(PresentationRuntime::query()->count())->toBe(1);
    expect($runtime->voting_id)->toBe($electionVoting->id);
    expect($runtime->content_type)->toBe('candidate_admission');
    expect($runtime->context)->toBe(['admission_id' => 42, 'device_group_id' => 3]);

    expect($manager->clear()->only(['voting_id', 'content_type', 'context']))
        ->toBe(['voting_id' => null, 'content_type' => 'none', 'context' => []]);
});
