<?php

use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionRoundManager;
use App\Services\ElectionRoundSnapshotRecovery;
use App\Services\Voting\VotingDeviceRoster;

it('recovers a zero snapshot on an empty live round after device weights are saved', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'quorum_participant_count' => 100,
    ]);
    $election->createDefaultContests();
    app(VotingDeviceRoster::class)->ensure($voting);

    $device = Device::query()->where('device_number', '001')->firstOrFail();
    VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $device->id)
        ->update(['weight' => 4]);

    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    $manager = app(ElectionRoundManager::class);
    $round = $manager->create($contest);
    $round->update([
        'status' => 'live',
        'opened_at' => now(),
        'eligible_weight_total' => 0,
        'quorum_participant_count_snapshot' => 100,
    ]);

    expect(app(ElectionRoundSnapshotRecovery::class)->recoverEmptyLiveRounds($voting))->toBe(1)
        ->and($round->fresh()->eligible_weight_total)->toBe('4.00')
        ->and($round->eligibleDeviceWeights()->count())->toBe(1)
        ->and($round->eligibleDeviceWeights()->value('weight_snapshot'))->toBe('4.00');

    $manager->recordVote($round, $round->candidates()->firstOrFail(), $device);

    expect($round->votes()->count())->toBe(1);
});

it('never rewrites a live round that already has a device snapshot', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'quorum_participant_count' => 100,
    ]);
    $election->createDefaultContests();
    app(VotingDeviceRoster::class)->ensure($voting);

    $device = Device::query()->where('device_number', '001')->firstOrFail();
    VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $device->id)
        ->update(['weight' => 8]);

    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    $round = app(ElectionRoundManager::class)->create($contest);
    $round->update([
        'status' => 'live',
        'opened_at' => now(),
        'eligible_weight_total' => 2,
        'quorum_participant_count_snapshot' => 100,
    ]);
    $round->eligibleDeviceWeights()->create([
        'device_id' => $device->id,
        'weight_snapshot' => 2,
    ]);

    expect(app(ElectionRoundSnapshotRecovery::class)->recoverEmptyLiveRounds($voting))->toBe(0)
        ->and($round->fresh()->eligible_weight_total)->toBe('2.00')
        ->and($round->eligibleDeviceWeights()->value('weight_snapshot'))->toBe('2.00');
});
