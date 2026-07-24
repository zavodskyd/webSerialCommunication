<?php

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionCandidateAdmissionManager;
use App\Services\SerialAgent\SerialAgentFrameHandler;
use App\Support\PresentationRuntimeManager;

test('serial frames vote for the active candidate admission and replace the device vote', function () {
    [$voting, $admission, $device] = activeAdmissionFixture();

    $handler = app(SerialAgentFrameHandler::class);
    $first = $handler->handle(qomoFrameFor(1, 'A'));
    $second = $handler->handle(qomoFrameFor(1, 'B'));

    expect($first?->accepted)->toBeTrue();
    expect($second?->accepted)->toBeTrue();
    expect($admission->votes()->count())->toBe(1);
    expect($admission->votes()->where('device_id', $device->id)->value('option_key'))->toBe('B');
    expect($second?->results)->toContain(['key' => 'B', 'label' => 'Proti', 'color' => 'rose', 'vote_count' => 1, 'weighted_total' => 4.0]);
    expect(VoteEvent::query()->whereNull('voting_question_id')->count())->toBe(2);
});

test('an active localized admission rejects a serial frame from outside its device range', function () {
    [, $admission] = activeAdmissionFixture();
    $outsideDevice = Device::query()->create([
        'device_number' => '010',
        'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '',
    ]);
    VotingAttendee::query()->create([
        'voting_id' => $admission->election->voting_id,
        'device_id' => $outsideDevice->id,
        'weight' => 3,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(10, 'A'));

    expect($result?->accepted)->toBeFalse();
    expect($result?->rejectionReason)->toBe('outside_device_group');
    expect($admission->votes()->count())->toBe(0);
    expect(VoteEvent::query()->where('device_id', $outsideDevice->id)->value('rejection_reason'))->toBe('outside_device_group');
});

/**
 * @return array{0: Voting, 1: ElectionCandidateAdmission, 2: Device}
 */
function activeAdmissionFixture(): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 9, 'quorum_participant_count' => 9]);
    $election->createDefaultContests();
    $group = DeviceGroup::query()->create(['election_id' => $election->id, 'name' => 'Hliny', 'sort_order' => 1, 'quorum_participant_count' => 7]);
    $group->ranges()->create(['start_number' => 1, 'end_number' => 9]);
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $admission = ElectionCandidateAdmission::query()->create([
        'election_id' => $election->id,
        'election_contest_id' => $contest->id,
        'device_group_id' => $group->id,
        'first_name' => 'Jana',
        'last_name' => 'Nováková',
    ]);
    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '',
    ]);
    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 4,
        'is_present' => true,
        'can_vote' => true,
    ]);

    app(ElectionCandidateAdmissionManager::class)->start($admission);

    app(PresentationRuntimeManager::class)->activate($voting, 'candidate_admission', ['admission_id' => $admission->id]);

    return [$voting, $admission, $device];
}
