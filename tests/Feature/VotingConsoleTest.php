<?php

use App\Livewire\Voting\VotingConsole;
use App\Models\Device;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use Illuminate\Support\Carbon;

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

test('helper driver: liveTick marks helper as unhealthy when not running', function () {
    config(['serial.driver' => 'node-helper']);

    [, $voting] = createConsoleFixture();

    $component = app(VotingConsole::class);
    $component->mount($voting);

    expect($component->helperHealthy)->toBeNull();

    // No helper port file exists in tests, so SerialHelperClient::health() returns ok=false.
    $component->liveTick();

    expect($component->helperHealthy)->toBeFalse();
    expect($component->helperQueuedFrames)->toBe(0);
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

test('node helper buffers frames to disk when laravel POST fails', function () {
    $helper = file_get_contents(base_path('electron/serial-helper/index.js'));

    // The retry buffer is the conference-day insurance: if Laravel is briefly
    // unreachable, frames must not be dropped silently.
    expect($helper)
        ->toContain('const QUEUE_FILE')
        ->toContain('serial-helper-queue.jsonl')
        ->toContain('const QUEUE_DRAIN_INTERVAL_MS')
        ->toContain('const QUEUE_MAX_ATTEMPTS')
        ->toContain('function loadQueueFromDisk()')
        ->toContain('function persistQueue()')
        ->toContain('function scheduleQueueDrain()')
        ->toContain('async function drainQueue()')
        ->toContain('outbox.push(entry)')
        ->toContain('queuedFrames: outbox.length');
});

test('helper driver: pause then resume does not count paused wall-clock time toward the timer', function () {
    config(['serial.driver' => 'node-helper']);

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

test('stale native vote requests are accepted once the question is live in runtime state', function () {
    [, $voting, , $device] = createConsoleFixture();

    $staleComponent = app(VotingConsole::class);
    $staleComponent->mount($voting);

    $starterComponent = app(VotingConsole::class);
    $starterComponent->mount($voting);
    $starterComponent->startQuestion();

    expect($staleComponent->collectorEnabled)->toBeFalse();

    $response = $staleComponent->recordVoteFromCode($device->code_a);

    expect($response)->toMatchArray([
        'accepted' => true,
        'lastMatchedDeviceNumber' => '001',
        'lastButtonName' => 'A',
    ]);
    expect(Vote::query()->count())->toBe(1);
    expect(Vote::query()->first()->option_key)->toBe('A');
});

test('native serial runtime separates connection from transceiver collection', function () {
    $consoleView = file_get_contents(resource_path('views/livewire/voting/voting-console.blade.php'));

    expect($consoleView)
        ->toContain('wire:ignore')
        ->toContain('state.serialPort = await navigator.serial.requestPort();')
        ->toContain('await initializeDevice();')
        ->toContain('const startQuestionFromFrontend = async () => {')
        ->toContain('await startCommunication();')
        ->toContain("await sendHexCommand('5b80db', 3);")
        ->toContain("await sendHexCommand('5a80da', 3);")
        ->toContain('const finishQuestionFromFrontend = async (remainingSeconds = state.remainingSeconds) => {')
        ->toContain('await stopCommunication();')
        ->toContain('readerStopPromise: null')
        ->toContain('state.readerStopPromise = readData();')
        ->toContain('await waitForReaderStop();')
        ->toContain('!state.isConnected || state.collectorEnabled || state.preparingQuestionStart')
        ->toContain('if (state.collectorEnabled || state.preparingQuestionStart) {')
        ->toContain('if (state.isReading || state.reader || state.readerStopPromise || state.stoppingCommunication) {')
        ->toContain("window.addEventListener('pagehide', disconnectBeforeLeavingConsole);")
        ->toContain("document.addEventListener('livewire:navigating', disconnectBeforeLeavingConsole);")
        ->not->toContain('serialPortFilters')
        ->not->toContain('!state.isConnected || state.collectorEnabled || state.preparingQuestionStart || state.isReading')
        ->not->toContain('if (state.collectorEnabled || state.preparingQuestionStart || state.isReading) {')
        ->not->toContain('wire:click="finishQuestion"');

    expect(substr_count($consoleView, 'await startCommunication();'))->toBe(2);
});

test('native app shows all serial ports for manual usb selection', function () {
    $mainProcess = file_get_contents(base_path('nativephp/electron/src/main/index.js'));

    expect($mainProcess)
        ->toContain("import {app, BrowserWindow, ipcMain, session} from 'electron'")
        ->toContain("session.defaultSession.on('select-serial-port'")
        ->toContain('async (event, portList, webContents, callback)')
        ->toContain('formatSerialPortLabel')
        ->toContain('showSerialPortPicker')
        ->toContain('display: flex; flex-direction: column; gap: 8px;')
        ->toContain('ipcMain.once(channel')
        ->toContain("'Vyber USB zariadenie'")
        ->toContain('Number(isVotingUsbAdapter(right)) - Number(isVotingUsbAdapter(left))')
        ->not->toContain('dialog.showMessageBoxSync')
        ->not->toContain('const selectedPort = portList.find(isVotingUsbAdapter);');
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
