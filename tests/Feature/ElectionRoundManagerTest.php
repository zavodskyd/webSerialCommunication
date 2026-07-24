<?php

use App\Exceptions\ElectionVoteRejected;
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
    $election->update(['quorum_participant_count' => 100]);
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
    try {
        $manager->recordVote($round, $candidate, $device);
    } catch (ElectionVoteRejected $exception) {
        expect($exception->reason)->toBe('duplicate_vote');
    }

    expect($round->votes()->count())->toBe(1);
    expect($round->votes()->value('weight_snapshot'))->toBe('3.00');
    expect($manager->results($round)['accepted_device_count'])->toBe(1);
});

test('majority is calculated from the general participant count snapshot', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'quorum_participant_count' => 100]);
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

    $election->update(['quorum_participant_count' => 20]);

    expect($round->quorum_participant_count_snapshot)->toBe(100);
    expect($manager->results($round)['majority_threshold'])->toBe(51.0);
});

test('all election contest types use the general participant count', function () {
    foreach (['chairperson', 'board-hliny', 'supervisory-committee'] as $contestKey) {
        $voting = Voting::query()->create(['name' => 'Voľby '.$contestKey, 'voting_type' => 'election']);
        $election = Election::query()->create(['voting_id' => $voting->id, 'quorum_participant_count' => 120]);
        $election->createDefaultContests();
        $contest = $election->contests()->where('key', $contestKey)->firstOrFail();
        $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);

        $round = openElectionRound(app(ElectionRoundManager::class), $contest);

        expect($round->quorum_participant_count_snapshot)->toBe(120);
    }
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
    try {
        $manager->recordVote($round, $round->candidates()->skip(1)->firstOrFail(), $device);
    } catch (ElectionVoteRejected $exception) {
        expect($exception->reason)->toBe('duplicate_vote');
    }

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

test('a successor round limits each device to the remaining seats', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-solinky')->firstOrFail();
    foreach (range(1, 5) as $number) {
        $contest->candidates()->create(['first_name' => 'Kandidát', 'last_name' => (string) $number]);
    }
    $device = electionRoundVoter($voting, '250', 10);
    $manager = app(ElectionRoundManager::class);
    $firstRound = openElectionRound($manager, $contest);
    $manager->recordVote($firstRound, $firstRound->candidates()->firstOrFail(), $device);
    $manager->recordVote($firstRound, $firstRound->candidates()->skip(1)->firstOrFail(), $device);
    $manager->close($firstRound);

    $secondRound = $contest->rounds()->reorder()->latest('round_number')->firstOrFail();
    $manager->open($secondRound);
    $manager->recordVote($secondRound, $secondRound->candidates()->firstOrFail(), $device);

    expect($manager->results($secondRound)['remaining_seats'])->toBe(1);
    expect(fn () => $manager->recordVote($secondRound, $secondRound->candidates()->skip(1)->firstOrFail(), $device))
        ->toThrow(ElectionVoteRejected::class, 'Zariadenie už podporilo maximálny počet kandidátov v tomto kole.');
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
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'weight_one_device_count' => 3,
        'quorum_participant_count' => 100,
    ]);
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

    expect($round->eligibleDeviceWeights()->pluck('weight_snapshot')->all())->toBe(['3.00', '99.00']);
    expect($manager->results($round))->toMatchArray([
        'total_weight' => 102.0,
        'majority_threshold' => 51.0,
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
    if ($contest->election->quorum_participant_count === null) {
        $eligibleWeight = VotingAttendee::query()
            ->where('voting_id', $contest->election->voting_id)
            ->where('is_present', true)
            ->where('can_vote', true)
            ->where('weight', '>=', 1)
            ->sum('weight');

        $contest->election->update(['quorum_participant_count' => max(1, (int) $eligibleWeight)]);
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
