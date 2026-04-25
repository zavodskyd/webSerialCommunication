<?php

use App\Livewire\Voting\VotingConsole;
use App\Models\Device;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;

test('pause keeps collector active and still accepts votes', function () {
    [, $voting, , $device] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->pauseQuestion();
    $response = $component->recordVoteFromCode($device->code_a);

    expect($component->timerRunning)->toBeFalse();
    expect($component->collectorEnabled)->toBeTrue();
    expect($component->lastMatchedDeviceNumber)->toBe('001');
    expect($component->lastButtonName)->toBe('A');
    expect($response)->toMatchArray([
        'accepted' => true,
        'lastMatchedDeviceNumber' => '001',
        'lastButtonName' => 'A',
    ]);
    expect($response['results'][0]['weighted_total'])->toBe(5.0);
    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');
});

test('start resumes a paused question without resetting the remaining time', function () {
    [, $voting, $question] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->syncRemainingSeconds(12);
    $component->pauseQuestion();
    $component->startQuestion();

    expect($component->timerRunning)->toBeTrue();
    expect($component->collectorEnabled)->toBeTrue();
    expect($component->remainingSeconds)->toBe(12);
    expect($question->fresh()->status)->toBe('live');
});

test('start returns immediate console state for native serial runtime', function () {
    [, $voting] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $state = $component->startQuestion();

    expect($state)->toMatchArray([
        'collectorEnabled' => true,
        'timerRunning' => true,
        'remainingSeconds' => 30,
        'resultsVisible' => false,
    ]);
});

test('console persists runtime state for presentation polling', function () {
    [, $voting, $question] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->syncRemainingSeconds(18);
    $component->pauseQuestion();

    $voting->refresh();

    expect($voting->current_voting_question_id)->toBe($question->id);
    expect($voting->runtime_remaining_seconds)->toBe(18);
    expect($voting->runtime_timer_running)->toBeFalse();
    expect($voting->runtime_collector_enabled)->toBeTrue();
    expect($voting->runtime_results_visible)->toBeFalse();
});

test('device with zero vote weight is ignored in voting results', function () {
    [, $voting, , $device] = createConsoleFixture(weight: 0);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $response = $component->recordVoteFromCode($device->code_a);

    expect($response)->toMatchArray([
        'accepted' => false,
        'message' => 'Zariadenie 001 má nastavený počet hlasov 0.',
    ]);
    expect(Vote::query()->count())->toBe(0);
    expect($response['results'][0]['weighted_total'])->toBe(0.0);
});

test('console shows results and advances after closing them', function () {
    [, $voting, $firstQuestion] = createConsoleFixture(autoShowResults: true);

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);

    expect($component->resultsVisible)->toBeTrue();

    $component->closeResultsAndAdvance();

    expect($component->resultsVisible)->toBeFalse();
    expect($component->currentQuestionId)->toBe($secondQuestion->id);
    expect($component->remainingSeconds)->toBe(20);
    expect($firstQuestion->fresh()->status)->toBe('closed');
});

test('console can start the next question after automatic results are closed', function () {
    [, $voting] = createConsoleFixture(autoShowResults: true);

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);
    $component->closeResultsAndAdvance();
    $component->startQuestion();

    expect($component->currentQuestionId)->toBe($secondQuestion->id);
    expect($component->timerRunning)->toBeTrue();
    expect($component->collectorEnabled)->toBeTrue();
    expect($component->resultsVisible)->toBeFalse();
    expect($secondQuestion->fresh()->status)->toBe('live');
});

test('console can manually show results without advancing after closing', function () {
    [, $voting, $firstQuestion] = createConsoleFixture(autoShowResults: false);

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->showResults();

    expect($component->resultsVisible)->toBeTrue();
    expect($voting->fresh()->runtime_results_visible)->toBeTrue();

    $component->closeResultsAndAdvance();

    expect($component->resultsVisible)->toBeFalse();
    expect($component->currentQuestionId)->toBe($firstQuestion->id);
    expect($component->currentQuestionId)->not->toBe($secondQuestion->id);
});

test('starting the same closed question again overwrites prior finished results', function () {
    [, $voting, $question, $device] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->recordVoteFromCode($device->code_a);
    $component->finishQuestion(0);

    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');

    $component->startQuestion();
    $component->recordVoteFromCode($device->code_b);
    $component->finishQuestion(0);

    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('B');
    expect($question->fresh()->votes()->count())->toBe(1);
});

test('when automatic results are disabled console advances immediately after finish', function () {
    [, $voting] = createConsoleFixture(autoShowResults: false);

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 15,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);

    expect($component->resultsVisible)->toBeFalse();
    expect($component->currentQuestionId)->toBe($secondQuestion->id);
});

/**
 * @return array{0: User, 1: Voting, 2: VotingQuestion, 3: Device}
 */
function createConsoleFixture(bool $autoShowResults = true, int $weight = 5): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $voting = Voting::query()->create([
        'name' => 'Valné zhromaždenie',
        'auto_show_results' => $autoShowResults,
    ]);

    $question = $voting->createQuestionWithDefaults(
        order: 1,
        label: 'Hlasovanie 1',
        text: 'Schváliť program?',
        responseTimeSeconds: 30,
    );

    $device = Device::query()->create([
        'device_number' => '001',
        'code_a' => '2081a1',
        'code_b' => '2091b1',
        'code_c' => '20a181',
        'code_d' => '',
        'code_e' => '',
        'code_f' => '',
        'code_ruka' => '',
    ]);

    VotingAttendee::query()->create([
        'voting_id' => $voting->id,
        'device_id' => $device->id,
        'weight' => $weight,
        'is_present' => true,
        'can_vote' => true,
    ]);

    return [$user, $voting, $question, $device];
}
