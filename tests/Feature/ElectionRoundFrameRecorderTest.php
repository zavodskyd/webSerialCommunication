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

test('a frame received at the deadline is counted and a later frame is rejected', function () {
    [$round] = activeRoundFixture();
    $round->update([
        'opened_at' => now()->startOfSecond(),
        'response_time_seconds' => 30,
    ]);
    $deadline = $round->fresh()->opened_at->toImmutable()->addSeconds(30);
    $handler = app(SerialAgentFrameHandler::class);

    $accepted = $handler->handle(qomoFrameFor(1, 'A'), $deadline);
    $late = $handler->handle(qomoFrameFor(1, 'A'), $deadline->addSecond());

    expect($accepted?->accepted)->toBeTrue();
    expect($late?->accepted)->toBeFalse();
    expect($late?->rejectionReason)->toBe('after_deadline');
    expect($round->votes()->count())->toBe(1);
    expect(VoteEvent::query()->where('election_round_id', $round->id)->latest('id')->value('received_at')->getTimestamp())->toBe($deadline->addSecond()->getTimestamp());
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

test('a duplicate round vote is rejected with a specific audit reason', function () {
    [$round] = activeRoundFixture();
    $handler = app(SerialAgentFrameHandler::class);

    expect($handler->handle(qomoFrameFor(1, 'A'))?->accepted)->toBeTrue();
    $duplicate = $handler->handle(qomoFrameFor(1, 'A'));

    expect($duplicate?->accepted)->toBeFalse();
    expect($duplicate?->rejectionReason)->toBe('duplicate_vote');
    expect($round->votes()->count())->toBe(1);
    expect(VoteEvent::query()->where('election_round_id', $round->id)->latest('id')->value('rejection_reason'))->toBe('duplicate_vote');
});

test('a zero weight device is rejected and audited without an exception leak', function () {
    [$round] = activeRoundFixture();
    $device = Device::query()->create([
        'device_number' => '002',
        'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '',
    ]);
    VotingAttendee::query()->create([
        'voting_id' => $round->contest->election->voting_id,
        'device_id' => $device->id,
        'weight' => 0,
        'is_present' => true,
        'can_vote' => true,
    ]);

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(2, 'A'));

    expect($result?->accepted)->toBeFalse();
    expect($result?->rejectionReason)->toBe('zero_weight');
    expect(VoteEvent::query()->where('device_id', $device->id)->value('rejection_reason'))->toBe('zero_weight');
});

test('a round serial frame is rejected while the candidate collector is stopped', function () {
    [$round] = activeRoundFixture();
    $round->contest->election->voting->update(['runtime_collector_enabled' => false]);

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(1, 'A'));

    expect($result?->accepted)->toBeFalse();
    expect($round->votes()->count())->toBe(0);
});

test('election presentation includes the adaptive no-scroll candidate table', function () {
    [$round] = activeRoundFixture(8);

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('data-election-candidate-table', false)
        ->assertSee('grid-flow-col', false)
        ->assertSee('ResizeObserver', false)
        ->assertSee('grid-cols-[5rem_1fr_12rem_10rem]', false)
        ->assertDontSee('Kandidátka · poradie, meno, hlasy a stav');
});

test('election presentation keeps the original row layout below the compacting threshold', function () {
    [$round] = activeRoundFixture();

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('compact: false', false)
        ->assertSee('grid-cols-[5rem_1fr_12rem_10rem]', false)
        ->assertSee('flex h-36 w-96 items-center justify-center', false)
        ->assertSee('flex min-h-36 flex-1 flex-col items-start justify-center', false);
});

test('election presentation tells voters the maximum number of candidates they can mark', function () {
    [$round] = activeRoundFixture();

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('Možno označiť najviac')
        ->assertSee((string) $round->contest->seat_count)
        ->assertSee('kandidátov.')
        ->assertDontSee('Mandátov:');
});

test('election presentation shows the remaining time in its info panel', function () {
    [$round] = activeRoundFixture();
    $round->contest->election->voting->update([
        'runtime_collector_enabled' => true,
        'runtime_timer_running' => true,
        'runtime_remaining_seconds' => 29,
    ]);

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee('00:29')
        ->assertSee('min-w-64 items-center justify-center px-6 py-3 text-5xl font-semibold', false)
        ->assertSee('Zariadení s platným hlasom:')
        ->assertDontSee('Väčšina:')
        ->assertDontSee('Čaká sa na spustenie hlasovania');
});

test('election presentation highlights the selected candidate before the timer starts', function () {
    [$round, $candidate] = activeRoundFixture();
    $round->update(['status' => 'draft']);
    $round->contest->election->voting->update(['runtime_collector_enabled' => false]);

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSee($candidate->first_name.' '.$candidate->last_name)
        ->assertSee('bg-emerald-100', false);
});

test('open election presentation remains alphabetical after candidates receive different totals', function () {
    [$round, , $device] = activeRoundFixture(3);
    $lastCandidate = $round->candidates()->orderByDesc('sort_order')->firstOrFail();
    $round->votes()->create([
        'election_round_candidate_id' => $lastCandidate->id,
        'device_id' => $device->id,
        'weight_snapshot' => 4,
        'voted_at' => now(),
    ]);
    $alphabeticalNames = $round->candidates()
        ->orderBy('last_name')
        ->orderBy('first_name')
        ->get()
        ->map(fn ($candidate): string => $candidate->first_name.' '.$candidate->last_name)
        ->all();

    $this->get(route('votings.presentation', $round->contest->election->voting))
        ->assertSuccessful()
        ->assertSeeInOrder($alphabeticalNames);
});

test('later round results prepend candidates elected in prior rounds with yellow styling', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 1, 'quorum_participant_count' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->where('key', 'board-hliny')->firstOrFail();
    $winner = $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);
    $remaining = $contest->candidates()->create(['first_name' => 'Bea', 'last_name' => 'Bérová']);

    $firstRound = $contest->rounds()->create(['round_number' => 1, 'status' => 'closed', 'eligible_weight_total' => 1]);
    $firstWinner = $firstRound->candidates()->create([
        'election_candidate_id' => $winner->id,
        'first_name' => $winner->first_name,
        'last_name' => $winner->last_name,
        'sort_order' => 1,
        'status' => 'elected',
    ]);
    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '', 'code_b' => '', 'code_c' => '', 'code_d' => '', 'code_e' => '', 'code_f' => '', 'code_ruka' => '',
    ]);
    $firstWinner->votes()->create([
        'election_round_id' => $firstRound->id,
        'device_id' => $device->id,
        'weight_snapshot' => 1,
        'voted_at' => now(),
    ]);
    $secondRound = $contest->rounds()->create(['round_number' => 2, 'status' => 'closed', 'eligible_weight_total' => 1]);
    $secondRound->candidates()->create([
        'election_candidate_id' => $remaining->id,
        'first_name' => $remaining->first_name,
        'last_name' => $remaining->last_name,
        'sort_order' => 1,
    ]);
    $voting->update(['runtime_results_visible' => true]);
    app(PresentationRuntimeManager::class)->activate($voting, 'election_round', ['round_id' => $secondRound->id]);

    $this->get(route('votings.presentation', $voting))
        ->assertSuccessful()
        ->assertSeeInOrder(['Anna Adamová', 'Bea Bérová'])
        ->assertSee('bg-amber-100', false)
        ->assertSee('Zvolený skôr');
});

/**
 * @return array{0: ElectionRound, 1: ElectionRoundCandidate, 2: Device}
 */
function activeRoundFixture(int $candidateCount = 1): array
{
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 1, 'quorum_participant_count' => 1]);
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
    $voting->update(['runtime_collector_enabled' => true]);

    return [$round, $candidate, $device];
}
