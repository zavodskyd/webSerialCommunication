<?php

use App\Livewire\Voting\VotingConsole;
use App\Models\Device;
use App\Models\User;
use App\Models\Vote;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use App\Services\SerialAgent\SerialAgentFrameHandler;
use App\Support\SerialAgentClient;
use App\Support\SerialAgentMode;
use Illuminate\Support\Carbon;

test('pause keeps collector active and still accepts votes', function () {
    [, $voting, , $device] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->pauseQuestion();
    $response = $component->recordVoteFromCode(qomoFrameFor(1, 'A'));

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

    Carbon::setTestNow(now()->startOfSecond());

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();

    Carbon::setTestNow(now()->addSeconds(18));
    $component->pauseQuestion();

    Carbon::setTestNow(now()->addSeconds(60));
    $component->startQuestion();

    expect($component->timerRunning)->toBeTrue();
    expect($component->collectorEnabled)->toBeTrue();
    expect($component->remainingSeconds)->toBe(12);
    expect($question->fresh()->status)->toBe('live');

    Carbon::setTestNow();
});

test('start resumes a paused question without immediately skipping seconds', function () {
    [, $voting] = createConsoleFixture();

    Carbon::setTestNow(now()->startOfSecond()->addMilliseconds(750));

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();

    Carbon::setTestNow(now()->addSeconds(18));
    $component->pauseQuestion();

    Carbon::setTestNow(now()->addSeconds(60));
    $component->startQuestion();
    $component->liveTick();

    expect($component->timerRunning)->toBeTrue();
    expect($component->collectorEnabled)->toBeTrue();
    expect($component->remainingSeconds)->toBe(12);

    Carbon::setTestNow(now()->addSecond());
    $component->liveTick();

    expect($component->remainingSeconds)->toBe(11);

    Carbon::setTestNow();
});

test('rust agent driver: liveTick marks agent as unhealthy when not running', function () {
    [, $voting] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);

    expect($component->serialAgentHealthy)->toBeNull();

    // No serial-agent port file exists in tests, so SerialAgentClient::health() returns ok=false.
    $component->liveTick();

    expect($component->serialAgentHealthy)->toBeFalse();
    expect($component->serialAgentQueuedFrames)->toBe(0);
});

test('rust agent driver keeps collection running while paused and stops only on finish', function () {
    config(['serial.driver' => 'rust-agent']);

    [, $voting] = createConsoleFixture();

    $client = $this->mock(SerialAgentClient::class);
    $client->shouldReceive('command')->once()->with('start')->andReturnUsing(function (): array {
        Carbon::setTestNow(now()->addSeconds(2));

        return ['ok' => true];
    });
    $client->shouldReceive('stopAndDrain')->once()->andReturn(['ok' => true, 'drained' => true, 'collecting' => false, 'queued_frames' => 0]);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->serialConnected = true;

    $component->startQuestionViaHelper();
    expect($component->collectorEnabled)->toBeTrue();

    $component->pauseQuestionViaHelper();
    expect($component->timerRunning)->toBeFalse();
    expect($component->collectorEnabled)->toBeTrue();

    $component->finishQuestionViaHelper();
    expect($component->collectorEnabled)->toBeFalse();
});

test('entering operator console clears serial agent test mode before frames are handled', function () {
    [, $voting] = createConsoleFixture();

    SerialAgentMode::activateTest();

    $client = $this->mock(SerialAgentClient::class);
    $client->shouldReceive('command')->once()->with('start')->andReturn(['ok' => true]);

    $component = app(VotingConsole::class);
    $component->mount($voting);

    expect(SerialAgentMode::current())->toBeNull();

    $component->serialConnected = true;

    $component->startQuestionViaHelper();

    $result = app(SerialAgentFrameHandler::class)->handle(qomoFrameFor(1, 'A'));

    expect($result)->not->toBeNull();
    expect($result->accepted)->toBeTrue();
    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');

    SerialAgentMode::clear();
});

test('rust agent driver can start collection with the timer paused', function () {
    config(['serial.driver' => 'rust-agent']);

    [, $voting, $question] = createConsoleFixture();

    Carbon::setTestNow(now()->startOfSecond());

    $client = $this->mock(SerialAgentClient::class);
    $client->shouldReceive('command')->once()->with('start')->andReturn(['ok' => true]);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->serialConnected = true;

    $component->startQuestionPausedViaHelper();

    $voting->refresh();

    expect($component->collectorEnabled)->toBeTrue();
    expect($component->timerRunning)->toBeFalse();
    expect($component->remainingSeconds)->toBe(30);
    expect($voting->runtime_collector_enabled)->toBeTrue();
    expect($voting->runtime_timer_running)->toBeFalse();
    expect($voting->runtime_remaining_seconds)->toBe(30);
    expect($question->fresh()->status)->toBe('paused');

    Carbon::setTestNow();
});

test('events log toggle flips the visible flag', function () {
    [, $voting] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);

    expect($component->eventsLogVisible)->toBeFalse();

    $component->toggleEventsLog();
    expect($component->eventsLogVisible)->toBeTrue();

    $component->toggleEventsLog();
    expect($component->eventsLogVisible)->toBeFalse();
});

test('events log returns all events for the current question instead of truncating to 100', function () {
    [, $voting, $question, $device] = createConsoleFixture();
    $otherQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Schváliť zápisnicu?',
        responseTimeSeconds: 30,
    );

    for ($index = 0; $index < 101; $index++) {
        VoteEvent::query()->create([
            'voting_id' => $voting->id,
            'voting_question_id' => $question->id,
            'device_id' => $device->id,
            'raw_hex' => qomoFrameFor(1, 'A'),
            'source' => 'rust-agent',
            'button_name' => 'A',
            'accepted' => true,
            'rejection_reason' => null,
            'received_at' => now()->addSeconds($index),
        ]);
    }

    VoteEvent::query()->create([
        'voting_id' => $voting->id,
        'voting_question_id' => $otherQuestion->id,
        'device_id' => $device->id,
        'raw_hex' => qomoFrameFor(1, 'B'),
        'source' => 'rust-agent',
        'button_name' => 'B',
        'accepted' => true,
        'rejection_reason' => null,
        'received_at' => now()->addMinutes(10),
    ]);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->toggleEventsLog();

    $view = $component->render();
    $eventsLog = $view->getData()['eventsLog'];

    expect($eventsLog)->toHaveCount(101);
    expect($eventsLog->first()->received_at->timestamp)->toBeGreaterThan($eventsLog->last()->received_at->timestamp);
    expect($eventsLog->every(fn (VoteEvent $event) => $event->voting_question_id === $question->id))->toBeTrue();
});

test('native app no longer contains the legacy node serial helper launcher', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));

    expect($mainProcess)
        ->not->toContain('spawnSerialHelper')
        ->not->toContain('serial-helper.cjs')
        ->not->toContain('utilityProcess.fork');
});

test('rust agent driver: pause then resume does not count paused wall-clock time toward the timer', function () {
    [, $voting, $question] = createConsoleFixture();

    // Open the question 5s ago, then pause: 25s should be remaining.
    // Pin to whole-second boundary because opened_at gets second-truncated by SQLite
    // and Carbon's sub-second diffs would skew the assertions.
    Carbon::setTestNow(now()->startOfSecond());

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();

    // Travel 5s into the question.
    Carbon::setTestNow(now()->addSeconds(5));
    $component->pauseQuestion();

    expect($component->remainingSeconds)->toBe(25);

    // Stay paused for 60s of wall-clock. Without the fix this would consume
    // the 30s budget entirely; with the fix opened_at gets shifted on resume.
    Carbon::setTestNow(now()->addSeconds(60));
    $component->resumeQuestion();

    expect($component->timerRunning)->toBeTrue();
    expect($component->remainingSeconds)->toBe(25);

    // One more second, liveTick should report 24s remaining (not -36s).
    Carbon::setTestNow(now()->addSeconds(1));
    $component->liveTick();

    expect($component->remainingSeconds)->toBe(24);
    expect($question->fresh()->status)->toBe('live');

    Carbon::setTestNow();
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

test('operator console selects the first draft question on entry', function () {
    [, $voting, $firstQuestion] = createConsoleFixture();

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $firstQuestion->update([
        'status' => 'closed',
        'opened_at' => now()->subMinute(),
        'closed_at' => now(),
    ]);

    $component = app(VotingConsole::class);
    $component->mount($voting);

    expect($component->currentQuestionId)->toBe($secondQuestion->id);
    expect($voting->fresh()->current_voting_question_id)->toBe($secondQuestion->id);
});

test('stale native vote requests are accepted once the question is live in runtime state', function () {
    [, $voting, , $device] = createConsoleFixture();

    $staleComponent = app(VotingConsole::class);
    $staleComponent->mount($voting);

    $starterComponent = app(VotingConsole::class);
    $starterComponent->mount($voting);
    $starterComponent->startQuestion();

    expect($staleComponent->collectorEnabled)->toBeFalse();

    $response = $staleComponent->recordVoteFromCode(qomoFrameFor(1, 'A'));

    expect($response)->toMatchArray([
        'accepted' => true,
        'lastMatchedDeviceNumber' => '001',
        'lastButtonName' => 'A',
    ]);
    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');
});

test('operator console uses only the rust serial agent view', function () {
    $consoleView = file_get_contents(resource_path('views/livewire/voting/voting-console-helper.blade.php'));

    expect(is_file(resource_path('views/livewire/voting/voting-console.blade.php')))->toBeFalse();
    expect($consoleView)
        ->toContain('wire:poll.500ms="liveTick"')
        ->toContain('samostatnom okne Serial Agent')
        ->toContain('wire:click="startQuestionViaHelper"')
        ->toContain('wire:click="finishQuestionViaHelper"')
        ->not->toContain('navigator.serial')
        ->not->toContain('@script')
        ->not->toContain('sendHexCommand')
        ->not->toContain('SerialHelperClient')
        ->not->toContain('node-helper');
});

test('native app no longer registers browser web serial handlers', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));

    expect($mainProcess)
        ->toContain("import {app, BrowserWindow, dialog, ipcMain} from 'electron'")
        ->not->toContain("session.defaultSession.on('select-serial-port'")
        ->not->toContain('formatSerialPortLabel')
        ->not->toContain('showSerialPortPicker')
        ->not->toContain('isVotingUsbAdapter')
        ->not->toContain('configureWebSerial');
});

test('native app quits when its last window closes', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));

    expect($mainProcess)
        ->toContain("app.on('window-all-closed', () => {")
        ->toContain('app.quit();')
        ->toContain("app.once('quit', () => {")
        ->toContain('process.exit(0);');
});

test('native app uses windows taskkill when stopping child process trees', function () {
    $pluginSource = file_get_contents(base_path('nativephp/electron/electron-plugin/src/index.ts'));
    $childProcessApiSource = file_get_contents(base_path('nativephp/electron/electron-plugin/src/server/api/childProcess.ts'));

    expect($pluginSource)
        ->toContain('execFileSync("taskkill", ["/PID", String(pid), "/T", "/F"], { stdio: "ignore" });')
        ->toContain('process.platform === "win32"')
        ->toContain('killProcessTree(childProcess.pid)')
        ->toContain('process.platform !== "win32"');

    expect($childProcessApiSource)
        ->toContain("execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], {stdio: 'ignore'});")
        ->toContain("process.platform === 'win32'")
        ->toContain('killProcessTree(proc.pid)')
        ->toContain("process.platform !== 'win32'");
});

test('console persists runtime state for presentation polling', function () {
    [, $voting, $question] = createConsoleFixture();

    Carbon::setTestNow(now()->startOfSecond());

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();

    Carbon::setTestNow(now()->addSeconds(12));
    $component->pauseQuestion();

    $voting->refresh();

    expect($voting->current_voting_question_id)->toBe($question->id);
    expect($voting->runtime_remaining_seconds)->toBe(18);
    expect($voting->runtime_timer_running)->toBeFalse();
    expect($voting->runtime_collector_enabled)->toBeTrue();
    expect($voting->runtime_results_visible)->toBeFalse();

    Carbon::setTestNow();
});

test('live tick persists running timer for presentation polling', function () {
    [, $voting] = createConsoleFixture();

    Carbon::setTestNow(now()->startOfSecond());

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();

    Carbon::setTestNow(now()->addSeconds(7));
    $component->liveTick();

    expect($component->remainingSeconds)->toBe(23);
    expect($voting->fresh()->runtime_remaining_seconds)->toBe(23);
    expect($voting->fresh()->runtime_timer_running)->toBeTrue();
    expect($voting->fresh()->runtime_collector_enabled)->toBeTrue();

    Carbon::setTestNow();
});

test('device with zero vote weight is ignored in voting results', function () {
    [, $voting, , $device] = createConsoleFixture(weight: 0);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $response = $component->recordVoteFromCode(qomoFrameFor(1, 'A'));

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
    $component->recordVoteFromCode(qomoFrameFor(1, 'A'));
    $component->finishQuestion(0);

    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');

    $component->startQuestion();
    $component->recordVoteFromCode(qomoFrameFor(1, 'B'));
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

test('finishing the last question stamps finished_at and clears runtime flags', function () {
    [, $voting] = createConsoleFixture(autoShowResults: false);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);

    $voting->refresh();

    expect($voting->finished_at)->not->toBeNull();
    expect($voting->runtime_collector_enabled)->toBeFalse();
    expect($voting->runtime_timer_running)->toBeFalse();
    expect($voting->runtime_results_visible)->toBeFalse();
    expect($voting->runtime_remaining_seconds)->toBe(0);
});

test('closing the results modal of the last question stamps finished_at', function () {
    [, $voting] = createConsoleFixture(autoShowResults: true);

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);

    expect($component->resultsVisible)->toBeTrue();

    $component->closeResultsAndAdvance();

    $voting->refresh();

    expect($voting->finished_at)->not->toBeNull();
    expect($component->resultsVisible)->toBeFalse();
});

test('finishing a non-last question does not stamp finished_at', function () {
    [, $voting] = createConsoleFixture(autoShowResults: false);

    $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting);
    $component->startQuestion();
    $component->finishQuestion(0);

    $voting->refresh();

    expect($voting->finished_at)->toBeNull();
});

test('previous question moves to the nearest earlier question', function () {
    [, $voting, $firstQuestion] = createConsoleFixture();

    $secondQuestion = $voting->createQuestionWithDefaults(
        order: 2,
        label: 'Hlasovanie 2',
        text: 'Druhá otázka',
        responseTimeSeconds: 20,
    );

    $thirdQuestion = $voting->createQuestionWithDefaults(
        order: 3,
        label: 'Hlasovanie 3',
        text: 'Tretia otázka',
        responseTimeSeconds: 15,
    );

    $component = app(VotingConsole::class);
    $component->mount($voting, $thirdQuestion);
    $component->goToPreviousQuestion();

    expect($component->currentQuestionId)->toBe($secondQuestion->id);
    expect($component->currentQuestionId)->not->toBe($firstQuestion->id);
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
