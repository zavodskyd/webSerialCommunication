<?php

use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionRoundManager;

test('a round snapshots the current contest candidates and can be opened and closed', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Jana', 'last_name' => 'Nováková'],
        ['first_name' => 'Peter', 'last_name' => 'Zelený'],
    ]);

    $manager = app(ElectionRoundManager::class);
    $round = $manager->create($contest, 45);
    $contest->candidates()->delete();

    expect($round->round_number)->toBe(1);
    expect($round->response_time_seconds)->toBe(45);
    expect($round->candidates()->pluck('last_name')->all())->toBe(['Nováková', 'Zelený']);
    expect($manager->open($round)->status)->toBe('live');
    expect($manager->close($round)->status)->toBe('closed');
});

test('a round records one current weighted vote per device and candidate snapshot', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    $device = Device::query()->create(['device_number' => '001', 'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '']);
    VotingAttendee::query()->create(['voting_id' => $voting->id, 'device_id' => $device->id, 'weight' => 3, 'is_present' => true, 'can_vote' => true]);

    $manager = app(ElectionRoundManager::class);
    $round = $manager->open($manager->create($contest));
    $candidate = $round->candidates()->firstOrFail();
    $manager->recordVote($round, $candidate, $device);
    $manager->recordVote($round, $candidate, $device);

    expect($round->votes()->count())->toBe(1);
    expect($round->votes()->value('weight_snapshot'))->toBe('3.00');
});

test('majority is calculated from all received weighted votes in the round', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->createMany([['first_name' => 'Anna', 'last_name' => 'A'], ['first_name' => 'Bea', 'last_name' => 'B']]);
    $manager = app(ElectionRoundManager::class);
    $round = $manager->open($manager->create($contest));
    $round->votes()->createMany([
        ['election_round_candidate_id' => $round->candidates()->first()->id, 'device_id' => Device::query()->create(['device_number' => '010', 'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => ''])->id, 'weight_snapshot' => 60, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(1)->first()->id, 'device_id' => Device::query()->create(['device_number' => '011', 'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => ''])->id, 'weight_snapshot' => 100, 'voted_at' => now()],
    ]);

    expect($manager->results($round)['majority_threshold'])->toBe(81.0);
});

test('a chairperson device keeps its first support and ignores a later candidate', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->createMany([['first_name' => 'Anna', 'last_name' => 'A'], ['first_name' => 'Bea', 'last_name' => 'B']]);
    $device = Device::query()->create(['device_number' => '020', 'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '']);
    VotingAttendee::query()->create(['voting_id' => $voting->id, 'device_id' => $device->id, 'weight' => 1, 'is_present' => true, 'can_vote' => true]);
    $manager = app(ElectionRoundManager::class);
    $round = $manager->open($manager->create($contest));

    $manager->recordVote($round, $round->candidates()->firstOrFail(), $device);
    $manager->recordVote($round, $round->candidates()->skip(1)->firstOrFail(), $device);

    expect($round->votes()->count())->toBe(1);
    expect($round->votes()->first()->election_round_candidate_id)->toBe($round->candidates()->first()->id);
});
