<?php

use App\Models\Device;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionRoundManager;

test('election results export renders every closed round and candidate result', function () {
    [$voting, $round, $candidate] = electionExportFixture();

    $this->get(route('elections.exports.results', $voting))
        ->assertSuccessful()
        ->assertSee('Výsledok volieb')
        ->assertSee($round->contest->name.' · kolo 1')
        ->assertSee($candidate->first_name.' '.$candidate->last_name)
        ->assertSee('Zvolený/á');
});

test('election audit export streams round and candidate admission events with rejection reasons', function () {
    [$voting, $round, $candidate, $device] = electionExportFixture();
    VoteEvent::query()->create([
        'voting_id' => $voting->id,
        'election_round_id' => $round->id,
        'election_round_candidate_id' => $candidate->id,
        'device_id' => $device->id,
        'raw_hex' => '2081a1',
        'source' => 'rust-agent',
        'button_name' => 'A',
        'accepted' => false,
        'rejection_reason' => 'duplicate_vote',
        'received_at' => now(),
    ]);

    $response = $this->get(route('elections.exports.audit', $voting));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->streamedContent();

    expect($body)->toContain('received_at,context,contest,round,candidate_or_proposal,device_number,button_name,accepted,rejection_reason,raw_hex');
    expect($body)->toContain('election_round');
    expect($body)->toContain('duplicate_vote');
    expect($body)->toContain($candidate->first_name.' '.$candidate->last_name);
});

test('election exports reject standard votings', function () {
    $voting = Voting::query()->create(['name' => 'Štandardné hlasovanie']);

    $this->get(route('elections.exports.results', $voting))->assertNotFound();
    $this->get(route('elections.exports.audit', $voting))->assertNotFound();
});

/**
 * @return array{0: Voting, 1: ElectionRound, 2: ElectionRoundCandidate, 3: Device}
 */
function electionExportFixture(): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'active_device_limit' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);
    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '',
    ]);
    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => 1,
        'is_present' => true,
        'can_vote' => true,
    ]);
    $manager = app(ElectionRoundManager::class);
    $round = $manager->open($manager->create($contest));
    $candidate = $round->candidates()->firstOrFail();
    $manager->recordVote($round, $candidate, $device);
    $manager->close($round);

    return [$voting, $round->refresh(), $candidate->refresh(), $device];
}
