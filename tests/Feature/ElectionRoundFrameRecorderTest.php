<?php

use App\Models\Device;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Services\ElectionRoundManager;
use App\Services\SerialAgent\SerialAgentFrameHandler;
use App\Support\PresentationRuntimeManager;

test('a round serial frame is accepted and audited with its election context', function () {
    [$round, $candidate, $device] = activeRoundFixture();

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(1, 'A'));

    expect($result?->accepted)->toBeTrue();
    expect($round->votes()->count())->toBe(1);
    $this->assertDatabaseHas('vote_events', [
        'election_round_id' => $round->id,
        'election_round_candidate_id' => $candidate->id,
        'device_id' => $device->id,
        'accepted' => true,
    ]);
});

test('a rejected round serial frame is audited without creating a vote', function () {
    [$round, $candidate] = activeRoundFixture();

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(1, 'B'));

    expect($result?->accepted)->toBeFalse();
    expect($result?->rejectionReason)->toBe('non_voting_button');
    expect($round->votes()->count())->toBe(0);
    expect(VoteEvent::query()->where('election_round_id', $round->id)->value('rejection_reason'))->toBe('non_voting_button');
    expect(VoteEvent::query()->where('election_round_candidate_id', $candidate->id)->count())->toBe(1);
});

test('election presentation includes the adaptive no-scroll candidate table', function () {
    [$round] = activeRoundFixture(8);

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('data-election-candidate-table', false)
        ->assertSee('grid-flow-col', false)
        ->assertSee('ResizeObserver', false);
});

test('election presentation keeps the original row layout below the compacting threshold', function () {
    [$round] = activeRoundFixture();

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('compact: false', false)
        ->assertSee('grid-cols-[5rem_1fr_12rem_10rem]', false);
});

test('election presentation tells voters the maximum number of candidates they can mark', function () {
    [$round] = activeRoundFixture();

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('Možno označiť najviac '.$round->contest->seat_count.' kandidátov.')
        ->assertDontSee('Mandátov:');
});

/**
 * @return array{0: ElectionRound, 1: ElectionRoundCandidate, 2: Device}
 */
function activeRoundFixture(int $candidateCount = 1): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'active_device_limit' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'chairperson')->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Jana', 'last_name' => 'Nováková']);

    for ($candidateNumber = 2; $candidateNumber <= $candidateCount; $candidateNumber++) {
        $contest->candidates()->create([
            'first_name' => 'Kandidát',
            'last_name' => 'Číslo '.$candidateNumber,
        ]);
    }

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
    $round = app(ElectionRoundManager::class)->open(app(ElectionRoundManager::class)->create($contest));
    $candidate = $round->candidates()->firstOrFail();

    app(PresentationRuntimeManager::class)->activate($voting, 'election_round', [
        'round_id' => $round->id,
        'candidate_id' => $candidate->id,
    ]);

    return [$round, $candidate, $device];
}
