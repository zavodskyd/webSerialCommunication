<?php

use App\Livewire\Election\ElectionConsole;
use App\Models\Election;
use App\Models\Voting;
use App\Services\ElectionRoundManager;
use App\Support\PresentationRuntimeManager;
use App\Support\SerialAgentClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('the election console links back to its editor', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->assertSee('Späť do editora')
        ->assertSee('Otvoriť prezentačné okno')
        ->assertSeeHtml('href="'.route('elections.edit', $voting).'"')
        ->assertSeeHtml('href="'.route('votings.presentation', $voting).'"')
        ->assertSeeHtml('target="_blank"');
});

test('the election console hides the majority until the round snapshots its basis', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'quorum_participant_count' => 92]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $round = $contest->rounds()->firstOrFail();

    expect($component->html())->toMatch('/data-election-majority\\s+hidden/');

    app(ElectionRoundManager::class)->open($round);
    $component->refresh();

    expect($component->html())
        ->not->toMatch('/data-election-majority\\s+hidden/')
        ->toContain('väčšina 47');
});

test('the election console automatically selects the first candidate of a created round', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound')
        ->assertSet('candidateId', fn (?int $candidateId): bool => $candidateId === $contest->rounds()->firstOrFail()->candidates()->orderBy('sort_order')->value('id'))
        ->assertSet('remainingSeconds', 30);
});

test('the election console uses the configured response time for a created round', function () {
    $voting = Voting::query()->create([
        'name' => 'Voľby',
        'voting_type' => 'election',
        'default_response_time_seconds' => 10,
    ]);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound')
        ->assertSet('remainingSeconds', 10);

    expect($contest->rounds()->firstOrFail()->response_time_seconds)->toBe(10);
});

test('the election console advances to the next candidate only after the serial queue is drained', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 1, 'quorum_participant_count' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $component->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $firstCandidateId = $round->candidates()->orderBy('sort_order')->value('id');
    $secondCandidateId = $round->candidates()->orderBy('sort_order')->skip(1)->value('id');

    $component->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertSet('candidateId', $secondCandidateId)
        ->assertSet('collectorEnabled', false)
        ->assertSet('timerRunning', false);

    expect($firstCandidateId)->not->toBe($secondCandidateId);
});

test('the election console does not advance while the serial queue is not drained', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => false, 'drained' => false, 'error' => 'queue pending']);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id, 'weight_one_device_count' => 1, 'quorum_participant_count' => 1]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $component->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $firstCandidateId = $round->candidates()->orderBy('sort_order')->value('id');

    $component->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertSet('candidateId', $firstCandidateId)
        ->assertSet('collectorEnabled', true);

    expect($round->fresh()->status)->toBe('draft');
});

test('the election console closes the round after the last candidate without a server error', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'weight_one_device_count' => 1,
        'quorum_participant_count' => 1,
    ]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $round->update(['status' => 'live', 'opened_at' => now()]);

    $component->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertHasNoErrors()
        ->assertSet('candidateId', null)
        ->assertSet('collectorEnabled', false)
        ->assertSet('timerRunning', false)
        ->assertSet('resultsVisible', true);

    expect($round->fresh()->status)->toBe('closed')
        ->and($voting->fresh()->status)->toBe('draft');
});

test('a stale duplicate finalization of the last candidate is an idempotent no-op', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->twice()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create([
        'voting_id' => $voting->id,
        'weight_one_device_count' => 1,
        'quorum_participant_count' => 1,
    ]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    $firstRequest = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $secondRequest = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $round = $contest->rounds()->firstOrFail();
    $round->update(['status' => 'live', 'opened_at' => now()]);

    $firstRequest->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertHasNoErrors();

    $secondRequest->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertHasNoErrors()
        ->assertSet('candidateId', null)
        ->assertSet('collectorEnabled', false)
        ->assertSet('resultsVisible', true);

    expect($round->fresh()->status)->toBe('closed')
        ->and($contest->rounds()->count())->toBe(1);
});

test('the election console leaves finalization to the request holding the round mutex', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $firstCandidateId = $round->candidates()->orderBy('sort_order')->value('id');
    $lock = Cache::store('file')->lock("election-round-finalization:{$round->id}", 15);
    expect($lock->get())->toBeTrue();

    try {
        $component->set('collectorEnabled', true)
            ->call('stopRoundViaHelper')
            ->assertHasNoErrors()
            ->assertSet('candidateId', $firstCandidateId)
            ->assertSet('collectorEnabled', true);
    } finally {
        $lock->release();
    }
});

test('the election console retries a sqlite busy error only during finalization presentation activation', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $round = $contest->rounds()->firstOrFail();
    $secondCandidateId = $round->candidates()->orderBy('sort_order')->skip(1)->value('id');
    $actualRuntime = new PresentationRuntimeManager;
    $attempts = 0;
    $previous = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5);
    $previous->errorInfo = ['HY000', 5, 'database is locked'];
    $busyException = new QueryException('sqlite', 'update presentation_runtimes set context = ?', [], $previous);
    $runtime = Mockery::mock(PresentationRuntimeManager::class);
    $runtime->shouldReceive('current')->once()->andReturn($actualRuntime->current());
    $runtime->shouldReceive('activate')->twice()->andReturnUsing(function (...$arguments) use (&$attempts, $actualRuntime, $busyException) {
        $attempts++;

        if ($attempts === 1) {
            throw $busyException;
        }

        return $actualRuntime->activate(...$arguments);
    });
    app()->instance(PresentationRuntimeManager::class, $runtime);

    $component->set('collectorEnabled', true)
        ->call('stopRoundViaHelper')
        ->assertHasNoErrors()
        ->assertSet('candidateId', $secondCandidateId);

    expect($attempts)->toBe(2)
        ->and($actualRuntime->current()->context['candidate_id'])->toBe($secondCandidateId);
});

test('the election console does not retry unrelated presentation query failures', function () {
    $client = Mockery::mock(SerialAgentClient::class);
    $client->shouldReceive('health')->andReturn(['ok' => true, 'connected' => true])->byDefault();
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);
    app()->instance(SerialAgentClient::class, $client);

    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->createMany([
        ['first_name' => 'Anna', 'last_name' => 'Adamová'],
        ['first_name' => 'Bea', 'last_name' => 'Bérová'],
    ]);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting])
        ->call('createRound');
    $actualRuntime = new PresentationRuntimeManager;
    $previous = new PDOException('SQLSTATE[HY000]: General error: 1 no such table');
    $previous->errorInfo = ['HY000', 1, 'no such table'];
    $queryException = new QueryException('sqlite', 'update presentation_runtimes set context = ?', [], $previous);
    $runtime = Mockery::mock(PresentationRuntimeManager::class);
    $runtime->shouldReceive('current')->once()->andReturn($actualRuntime->current());
    $runtime->shouldReceive('activate')->once()->andThrow($queryException);
    app()->instance(PresentationRuntimeManager::class, $runtime);

    expect(fn () => $component->set('collectorEnabled', true)->call('stopRoundViaHelper'))
        ->toThrow(QueryException::class);
});

test('the manual result action remains available while the result is already displayed', function () {
    $voting = Voting::query()->create(['name' => 'Voľby', 'voting_type' => 'election']);
    $election = Election::query()->create(['voting_id' => $voting->id]);
    $election->createDefaultContests();
    $contest = $election->contests()->firstOrFail();
    $contest->candidates()->create(['first_name' => 'Anna', 'last_name' => 'Adamová']);

    $component = Livewire::test(ElectionConsole::class, ['voting' => $voting]);
    $component->call('createRound');
    $contest->rounds()->firstOrFail()->update(['status' => 'closed']);

    $component->set('resultsVisible', true)
        ->assertSeeHtml('wire:click="showRoundResults"')
        ->assertDontSeeHtml('wire:click="showRoundResults" disabled');
});
