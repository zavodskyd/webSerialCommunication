<?php

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionCandidateAdmissionManager;

test('the last valid admission vote replaces the prior device vote and accepted candidates are added', function () {
    [$election, $contest, $group] = admissionFixture();
    $firstDevice = admissionDevice('001', 3);
    $secondDevice = admissionDevice('002', 4);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $draftRound = $contest->rounds()->create(['round_number' => 2, 'status' => 'draft']);

    $manager = app(ElectionCandidateAdmissionManager::class);
    $manager->start($admission);
    $manager->recordVote($admission, $firstDevice, 'A');
    $manager->recordVote($admission, $firstDevice, 'B');
    $manager->recordVote($admission, $secondDevice, 'A');

    expect($admission->votes()->count())->toBe(2);
    expect($admission->votes()->where('device_id', $firstDevice->id)->value('option_key'))->toBe('B');

    $manager->stop($admission);
    $resolvedAdmission = $manager->showResults($admission);
    expect($resolvedAdmission->status)->toBe('accepted');
    expect($manager->resolve($admission)->status)->toBe('accepted');
    expect($contest->candidates()->where('first_name', 'Jana')->where('last_name', 'Nováková')->exists())->toBeTrue();
    expect($draftRound->candidates()->where('first_name', 'Jana')->where('last_name', 'Nováková')->exists())->toBeTrue();
});

test('local candidate admission uses the participant quorum snapshot with weighted yes votes', function () {
    [$election, $contest, $group] = admissionFixture();
    $election->update(['weight_one_device_count' => 2]);
    $group->update(['quorum_participant_count' => 9]);
    $firstDevice = admissionDevice('001', 4);
    $secondDevice = admissionDevice('002', 4);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);

    $manager = app(ElectionCandidateAdmissionManager::class);
    $started = $manager->start($admission);
    $manager->recordVote($admission, $firstDevice, 'A');
    VotingAttendee::query()->where('device_id', $secondDevice->id)->update(['weight' => 100]);
    $group->update(['quorum_participant_count' => 1]);
    $manager->stop($admission);
    $manager->showResults($admission);

    expect($started->eligible_weight_total)->toBe(8.0);
    expect($started->quorum_participant_count_snapshot)->toBe(9);
    expect($started->eligibleDeviceWeights()->count())->toBe(2);
    expect($manager->resolve($admission)->status)->toBe('rejected');
});

test('local candidate admission requires a quorum count and restart refreshes its snapshot', function () {
    [$election, $contest, $group] = admissionFixture();
    $group->update(['quorum_participant_count' => null]);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $manager = app(ElectionCandidateAdmissionManager::class);

    expect(fn () => $manager->start($admission))
        ->toThrow(InvalidArgumentException::class, 'Pre vybranú lokalitu nastavte počet účastníkov pre kvórum.');

    $group->update(['quorum_participant_count' => 7]);
    expect($manager->start($admission)->quorum_participant_count_snapshot)->toBe(7);

    $manager->stop($admission);
    $group->update(['quorum_participant_count' => 11]);

    expect($manager->restart($admission)->quorum_participant_count_snapshot)->toBe(11);
});

test('supervisory committee candidate admission uses the general participant count snapshot', function () {
    [$election] = admissionFixture();
    $contest = $election->contests()->where('key', 'supervisory-committee')->firstOrFail();
    $firstDevice = admissionDevice('001', 4);
    admissionDevice('002', 4);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => null,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $manager = app(ElectionCandidateAdmissionManager::class);

    $started = $manager->start($admission);
    $manager->recordVote($admission, $firstDevice, 'A');
    $election->update(['quorum_participant_count' => 1]);
    $manager->stop($admission);
    $manager->showResults($admission);

    expect($started->eligible_weight_total)->toBe(8.0)
        ->and($started->quorum_participant_count_snapshot)->toBe(9)
        ->and($manager->resolve($admission)->status)->toBe('rejected');
});

test('supervisory committee candidate admission requires the general participant count', function () {
    [$election] = admissionFixture();
    $election->update(['quorum_participant_count' => null]);
    $contest = $election->contests()->where('key', 'supervisory-committee')->firstOrFail();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => null,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);

    expect(fn () => app(ElectionCandidateAdmissionManager::class)->start($admission))
        ->toThrow(InvalidArgumentException::class, 'Pred spustením nastavte celkový počet účastníkov pre základ väčšiny.');
});

test('restarting an accepted admission removes only the candidate created by that admission', function () {
    [$election, $contest, $group] = admissionFixture();
    $device = admissionDevice('001', 4);
    $contest->rounds()->create(['round_number' => 1, 'status' => 'draft']);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $manager = app(ElectionCandidateAdmissionManager::class);

    $manager->start($admission);
    $manager->recordVote($admission, $device, 'A');
    $manager->stop($admission);
    $resolvedAdmission = $manager->showResults($admission);
    $createdCandidateId = $resolvedAdmission->created_election_candidate_id;

    expect($resolvedAdmission->status)->toBe('accepted')
        ->and($createdCandidateId)->not->toBeNull();

    $restartedAdmission = $manager->restart($resolvedAdmission);

    expect($restartedAdmission->status)->toBe('live')
        ->and($restartedAdmission->created_election_candidate_id)->toBeNull()
        ->and($restartedAdmission->results_visible)->toBeFalse()
        ->and($restartedAdmission->votes()->count())->toBe(0)
        ->and($contest->candidates()->whereKey($createdCandidateId)->exists())->toBeFalse();
});

test('restarting an accepted admission keeps a matching pre-existing candidate', function () {
    [$election, $contest, $group] = admissionFixture();
    $device = admissionDevice('001', 4);
    $candidate = $contest->candidates()->create([
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
        'status' => 'approved',
    ]);
    $contest->rounds()->create(['round_number' => 1, 'status' => 'draft']);
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $manager = app(ElectionCandidateAdmissionManager::class);

    $manager->start($admission);
    $manager->recordVote($admission, $device, 'A');
    $manager->stop($admission);
    $resolvedAdmission = $manager->showResults($admission);

    expect($resolvedAdmission->status)->toBe('accepted')
        ->and($resolvedAdmission->created_election_candidate_id)->toBeNull();

    $manager->restart($resolvedAdmission);

    expect($contest->candidates()->whereKey($candidate->id)->exists())->toBeTrue();
});

function admissionFixture(): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'weight_one_device_count' => 9,
        'quorum_participant_count' => 9,
    ]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $group = DeviceGroup::query()->create(['election_id' => $election->id, 'name' => 'Hliny', 'sort_order' => 1, 'quorum_participant_count' => 7]);
    $group->ranges()->create(['start_number' => 1, 'end_number' => 9]);

    return [$election, $contest, $group];
}

function admissionDevice(string $number, int $weight): Device
{
    $device = Device::query()->create(['device_number' => $number, 'code_a' => 'a'.$number, 'code_b' => 'b'.$number, 'code_c' => 'c'.$number, 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '']);
    $voting = Election::query()->latest('id')->firstOrFail()->voting;
    VotingAttendee::query()->create(['voting_id' => $voting->id, 'device_id' => $device->id, 'weight' => $weight, 'is_present' => true, 'can_vote' => true]);

    return $device;
}
