<?php

use App\Models\Device;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
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
    $election->update(['active_device_limit' => 100]);
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
    $round = openElectionRound($manager, $contest);
    $candidate = $round->candidates()->firstOrFail();
    $manager->recordVote($round, $candidate, $device);
    $manager->recordVote($round, $candidate, $device);

    expect($round->votes()->count())->toBe(1);
    expect($round->votes()->value('weight_snapshot'))->toBe('3.00');
    expect($manager->results($round)['accepted_device_count'])->toBe(1);
});

test('majority is calculated from the eligible device weight snapshot', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->createMany([['first_name' => 'Anna', 'last_name' => 'A'], ['first_name' => 'Bea', 'last_name' => 'B']]);
    $manager = app(ElectionRoundManager::class);
    $firstDevice = electionRoundVoter($voting, '010', 60);
    $secondDevice = electionRoundVoter($voting, '011', 100);
    $round = openElectionRound($manager, $contest);
    $round->votes()->createMany([
        ['election_round_candidate_id' => $round->candidates()->first()->id, 'device_id' => $firstDevice->id, 'weight_snapshot' => 60, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(1)->first()->id, 'device_id' => $secondDevice->id, 'weight_snapshot' => 100, 'voted_at' => now()],
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
    $round = openElectionRound($manager, $contest);

    $manager->recordVote($round, $round->candidates()->firstOrFail(), $device);
    $manager->recordVote($round, $round->candidates()->skip(1)->firstOrFail(), $device);

    expect($round->votes()->count())->toBe(1);
    expect($round->votes()->first()->election_round_candidate_id)->toBe($round->candidates()->first()->id);
});

test('an unsuccessful chairperson first round creates a runoff from the two highest non-zero results', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
        ['first_name' => 'Cyril', 'last_name' => 'Cibulík'],
    ]);

    $manager = app(ElectionRoundManager::class);
    $firstDevice = electionRoundVoter($voting, '101', 40);
    $secondDevice = electionRoundVoter($voting, '102', 35);
    $thirdDevice = electionRoundVoter($voting, '103', 25);
    $round = openElectionRound($manager, $contest);
    $round->votes()->createMany([
        ['election_round_candidate_id' => $round->candidates()->first()->id, 'device_id' => $firstDevice->id, 'weight_snapshot' => 40, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(1)->first()->id, 'device_id' => $secondDevice->id, 'weight_snapshot' => 35, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(2)->first()->id, 'device_id' => $thirdDevice->id, 'weight_snapshot' => 25, 'voted_at' => now()],
    ]);

    $manager->close($round);
    $runoff = $contest->rounds()->reorder()->latest('round_number')->firstOrFail();

    expect($runoff->round_number)->toBe(2);
    expect($runoff->candidates()->pluck('last_name')->all())->toBe(['Adamová', 'Bérová']);
    expect($round->candidates()->where('last_name', 'Cibulík')->value('status'))->toBe('eliminated');
});

test('a multi-seat contest carries forward unsuccessful candidates after eliminating the lowest result', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
        ['first_name' => 'Cyril', 'last_name' => 'Cibulík'],
        ['first_name' => 'Dana', 'last_name' => 'Dudová'],
    ]);

    $manager = app(ElectionRoundManager::class);
    $firstDevice = electionRoundVoter($voting, '201', 10);
    $secondDevice = electionRoundVoter($voting, '202', 8);
    $thirdDevice = electionRoundVoter($voting, '203', 1);
    $round = openElectionRound($manager, $contest);
    $round->votes()->createMany([
        ['election_round_candidate_id' => $round->candidates()->first()->id, 'device_id' => $firstDevice->id, 'weight_snapshot' => 10, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(1)->first()->id, 'device_id' => $secondDevice->id, 'weight_snapshot' => 8, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(3)->first()->id, 'device_id' => $thirdDevice->id, 'weight_snapshot' => 1, 'voted_at' => now()],
    ]);

    $manager->close($round);
    $nextRound = $contest->rounds()->reorder()->latest('round_number')->firstOrFail();

    expect($round->candidates()->where('last_name', 'Adamová')->value('status'))->toBe('elected');
    expect($round->candidates()->where('last_name', 'Cibulík')->value('status'))->toBe('eliminated');
    expect($nextRound->round_number)->toBe(2);
    expect($nextRound->candidates()->pluck('last_name')->all())->toBe(['Bérová', 'Dudová']);
});

test('a successor round includes candidates added after the preceding round', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
        ['first_name' => 'Cyril', 'last_name' => 'Cibulík'],
    ]);

    $manager = app(ElectionRoundManager::class);
    $firstDevice = electionRoundVoter($voting, '301', 40);
    $secondDevice = electionRoundVoter($voting, '302', 35);
    $thirdDevice = electionRoundVoter($voting, '303', 25);
    $round = openElectionRound($manager, $contest);
    $round->votes()->createMany([
        ['election_round_candidate_id' => $round->candidates()->first()->id, 'device_id' => $firstDevice->id, 'weight_snapshot' => 40, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(1)->first()->id, 'device_id' => $secondDevice->id, 'weight_snapshot' => 35, 'voted_at' => now()],
        ['election_round_candidate_id' => $round->candidates()->skip(2)->first()->id, 'device_id' => $thirdDevice->id, 'weight_snapshot' => 25, 'voted_at' => now()],
    ]);
    $contest->candidates()->create(['first_name' => 'Dana', 'last_name' => 'Dudová']);

    $manager->close($round);
    $runoff = $contest->rounds()->reorder()->latest('round_number')->firstOrFail();

    expect($runoff->candidates()->pluck('last_name')->all())->toBe(['Adamová', 'Bérová', 'Dudová']);
});

test('an opened round keeps individual device weights after global weight changes', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'active_device_limit' => 3]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);
    $device = electionRoundVoter($voting, '001', 3);
    electionRoundVoter($voting, '004', 99);

    $manager = app(ElectionRoundManager::class);
    $round = openElectionRound($manager, $contest);

    VotingAttendee::query()
        ->where('voting_id', $voting->id)
        ->where('device_id', $device->id)
        ->update(['weight' => 100]);

    $manager->recordVote($round, $round->candidates()->firstOrFail(), $device);

    expect($round->eligibleDeviceWeights()->pluck('weight_snapshot')->all())->toBe(['3.00']);
    expect($manager->results($round))->toMatchArray([
        'total_weight' => 3.0,
        'majority_threshold' => 2.0,
    ]);
    expect($round->votes()->value('weight_snapshot'))->toBe('3.00');
});

function electionRoundDevice(string $number): Device
{
    return Device::query()->create([
        'device_number' => $number,
        'code_a' => '',
        'code_b' => '',
        'code_c' => '',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);
}

function openElectionRound(ElectionRoundManager $manager, ElectionContest $contest): ElectionRound
{
    if ($contest->election->active_device_limit === null) {
        $contest->election->update(['active_device_limit' => 999]);
    }

    $round = $manager->create($contest);

    return $manager->open($round);
}

function electionRoundVoter(Voting $voting, string $number, int|float $weight): Device
{
    $device = electionRoundDevice($number);

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => $weight,
        'is_present' => true,
        'can_vote' => true,
    ]);

    return $device;
}
