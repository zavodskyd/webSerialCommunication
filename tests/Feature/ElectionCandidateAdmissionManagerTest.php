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

    $manager = app(ElectionCandidateAdmissionManager::class);
    $manager->start($admission);
    $manager->recordVote($admission, $firstDevice, 'A');
    $manager->recordVote($admission, $firstDevice, 'B');
    $manager->recordVote($admission, $secondDevice, 'A');

    expect($admission->votes()->count())->toBe(2);
    expect($admission->votes()->where('device_id', $firstDevice->id)->value('option_key'))->toBe('B');

    $manager->stop($admission);
    $manager->showResults($admission);
    expect($manager->resolve($admission)->status)->toBe('accepted');
    expect($contest->candidates()->where('first_name', 'Jana')->where('last_name', 'Nováková')->exists())->toBeTrue();
});

function admissionFixture(): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $group = DeviceGroup::query()->create(['election_id' => $election->id, 'name' => 'Hliny', 'sort_order' => 1]);
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
