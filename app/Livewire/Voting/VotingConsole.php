<?php

namespace App\Livewire\Voting;

use App\Models\Device;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingQuestion;
use App\Services\Voting\VoteRecorder;
use App\Support\SerialHelperClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

class VotingConsole extends Component
{
    public Voting $voting;

    public EloquentCollection $devices;

    public int $currentQuestionId;

    public int $remainingSeconds = 0;

    public bool $timerRunning = false;

    public bool $collectorEnabled = false;

    public bool $resultsVisible = false;

    public bool $advanceAfterResults = true;

    public ?string $lastVoteMessage = null;

    public ?string $lastMatchedDeviceNumber = null;

    public ?string $lastButtonName = null;

    /**
     * --- node-helper driver state (only meaningful when SERIAL_DRIVER=node-helper) ---
     */
    public bool $serialConnected = false;

    public ?string $selectedPortPath = null;

    public ?string $connectedPortPath = null;

    /**
     * @var array<int, array{path: string, manufacturer: ?string, vendor_id: ?string, product_id: ?string}>
     */
    public array $availablePorts = [];

    public function mount(Voting $voting, ?VotingQuestion $question = null): void
    {
        $this->voting = $voting;
        $this->devices = Device::query()
            ->orderBy('device_number')
            ->get();

        $currentQuestion = $question;

        if ($currentQuestion !== null && $currentQuestion->voting_id !== $this->voting->id) {
            abort(404);
        }

        $currentQuestion ??= $this->questions()->firstOrFail();

        $this->currentQuestionId = $currentQuestion->id;
        $this->resetQuestionState();
        $this->persistRuntimeState();
        $this->dispatchConsoleState();

        if ($this->isHelperDriver()) {
            $this->refreshSerialPorts();
        }
    }

    public function isHelperDriver(): bool
    {
        return config('serial.driver') === 'node-helper';
    }

    public function refreshSerialPorts(): void
    {
        $response = SerialHelperClient::call('list_ports');

        $this->availablePorts = is_array($response['ports'] ?? null) ? $response['ports'] : [];
    }

    public function connectSerial(): void
    {
        if ($this->selectedPortPath === null || $this->selectedPortPath === '') {
            return;
        }

        $open = SerialHelperClient::call('open', ['port_path' => $this->selectedPortPath]);

        if (! ($open['ok'] ?? false)) {
            $this->lastVoteMessage = 'Nepodarilo sa otvoriť port: '.($open['error'] ?? 'unknown');

            return;
        }

        SerialHelperClient::call('init');

        $this->serialConnected = true;
        $this->connectedPortPath = $this->selectedPortPath;
    }

    public function disconnectSerial(): void
    {
        if ($this->collectorEnabled) {
            return;
        }

        SerialHelperClient::call('close');

        $this->serialConnected = false;
        $this->connectedPortPath = null;
    }

    public function startQuestionViaHelper(): void
    {
        $payload = $this->startQuestion();

        if (($payload['collectorEnabled'] ?? false) === true) {
            SerialHelperClient::call('start');
        }
    }

    public function pauseQuestionViaHelper(): void
    {
        $this->pauseQuestion();
        SerialHelperClient::call('stop');
    }

    public function finishQuestionViaHelper(): void
    {
        SerialHelperClient::call('stop');
        $this->finishQuestion();
    }

    public function liveTick(): void
    {
        if ($this->isHelperDriver()) {
            $this->refreshLastVoteFromEvents();
        }

        if (! $this->collectorEnabled || ! $this->timerRunning) {
            return;
        }

        $question = $this->currentQuestion();

        if ($question->opened_at === null) {
            return;
        }

        $elapsed = $question->opened_at->diffInSeconds(now(), false);
        $this->remainingSeconds = max(0, $question->response_time_seconds - $elapsed);

        if ($this->remainingSeconds <= 0) {
            if ($this->isHelperDriver()) {
                SerialHelperClient::call('stop');
            }

            $this->finishQuestion(0);
        }
    }

    private function refreshLastVoteFromEvents(): void
    {
        $latest = VoteEvent::query()
            ->where('voting_question_id', $this->currentQuestionId)
            ->where('accepted', true)
            ->latest('received_at')
            ->with('device')
            ->first();

        if ($latest === null) {
            return;
        }

        $this->lastMatchedDeviceNumber = $latest->device?->device_number;
        $this->lastButtonName = $latest->button_name;
        $this->lastVoteMessage = 'Zariadenie '.($latest->device?->device_number ?? '?').' hlasovalo '.$latest->button_name.'.';
    }

    /**
     * @return array{collectorEnabled: bool, timerRunning: bool, remainingSeconds: int, resultsVisible: bool}
     */
    public function startQuestion(): array
    {
        if ($this->collectorEnabled && ! $this->timerRunning) {
            $this->resumeQuestion();

            return $this->consoleStatePayload();
        }

        $question = $this->currentQuestion();

        if ($question->status === 'closed') {
            $question->votes()->delete();
        }

        $question->update([
            'status' => 'live',
            'opened_at' => now(),
            'closed_at' => null,
        ]);

        $this->voting->update([
            'status' => 'live',
            'started_at' => $this->voting->started_at ?? now(),
            'finished_at' => null,
        ]);

        $this->remainingSeconds = $question->response_time_seconds;
        $this->timerRunning = true;
        $this->collectorEnabled = true;
        $this->resultsVisible = false;
        $this->lastVoteMessage = null;
        $this->persistRuntimeState();
        $this->dispatchConsoleState();

        return $this->consoleStatePayload();
    }

    public function pauseQuestion(): void
    {
        if (! $this->collectorEnabled) {
            return;
        }

        $this->timerRunning = false;

        $this->currentQuestion()->update([
            'status' => 'paused',
        ]);

        $this->persistRuntimeState();
        $this->dispatchConsoleState();
    }

    public function resumeQuestion(): void
    {
        if (! $this->collectorEnabled) {
            return;
        }

        $this->timerRunning = true;

        $this->currentQuestion()->update([
            'status' => 'live',
        ]);

        $this->persistRuntimeState();
        $this->dispatchConsoleState();
    }

    public function finishQuestion(?int $remainingSeconds = null): void
    {
        $question = $this->currentQuestion();

        if ($remainingSeconds !== null) {
            $this->remainingSeconds = max($remainingSeconds, 0);
        }

        $question->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->timerRunning = false;
        $this->collectorEnabled = false;
        $this->remainingSeconds = $question->response_time_seconds;
        $this->voting->update([
            'status' => 'draft',
        ]);

        if ($this->voting->auto_show_results) {
            $this->resultsVisible = true;
            $this->advanceAfterResults = true;
            $this->persistRuntimeState();
            $this->dispatchConsoleState();

            return;
        }

        $this->persistRuntimeState();
        $this->advanceToNextQuestion();
    }

    public function closeResultsAndAdvance(): void
    {
        $shouldAdvance = $this->advanceAfterResults;

        $this->resultsVisible = false;
        $this->advanceAfterResults = true;
        $this->persistRuntimeState();
        $this->dispatchConsoleState();

        if ($shouldAdvance) {
            $this->advanceToNextQuestion();
        }
    }

    public function showResults(): void
    {
        if ($this->collectorEnabled || $this->timerRunning) {
            return;
        }

        $this->resultsVisible = true;
        $this->advanceAfterResults = false;
        $this->persistRuntimeState();
        $this->dispatchConsoleState();
    }

    public function goToNextQuestion(): void
    {
        if ($this->isQuestionActive()) {
            return;
        }

        $this->advanceToNextQuestion();
    }

    public function goToPreviousQuestion(): void
    {
        if ($this->isQuestionActive()) {
            return;
        }

        $previousQuestion = $this->questions()
            ->where('order', '<', $this->currentQuestion()->order)
            ->reorder()
            ->orderByDesc('order')
            ->first();

        if (! $previousQuestion) {
            return;
        }

        $this->selectQuestion($previousQuestion->id);
    }

    public function selectQuestion(int $questionId): void
    {
        if ($this->isQuestionActive()) {
            return;
        }

        $question = $this->questions()
            ->whereKey($questionId)
            ->firstOrFail();

        $this->currentQuestionId = $question->id;
        $this->resetQuestionState();
        $this->persistRuntimeState();
        $this->dispatchConsoleState();
    }

    public function syncRemainingSeconds(int $remainingSeconds): void
    {
        $this->remainingSeconds = max($remainingSeconds, 0);
        $this->persistRuntimeState();
        $this->dispatchConsoleState();

        // Skip the Blade re-render — the live timer is driven by JS via
        // [data-remaining-time], the server-side value only needs to survive
        // a refresh. A full re-render every 5s morphs the buttons back to
        // their hardcoded `disabled` state and forces JS to re-sync, which
        // both flickers the UI and (more importantly) was masking a vote-flush
        // race in startQuestionFromFrontend.
        $this->skipRender();
    }

    /**
     * @return array{accepted: bool, message: string, lastMatchedDeviceNumber: ?string, lastButtonName: ?string, results: array<int, array{key: string, label: string, color: ?string, vote_count: int, weighted_total: float}>}
     */
    public function recordVoteFromCode(string $code): array
    {
        $question = $this->currentQuestion();

        $result = app(VoteRecorder::class)->record(
            code: $code,
            voting: $this->voting,
            question: $question,
            collectorEnabledHint: $this->collectorEnabled,
        );

        if ($result->accepted) {
            $this->lastMatchedDeviceNumber = $result->deviceNumber;
            $this->lastButtonName = $result->buttonName;
        }

        $this->lastVoteMessage = $result->message;
        $this->skipRender();

        return [
            'accepted' => $result->accepted,
            'message' => $result->message,
            'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
            'lastButtonName' => $this->lastButtonName,
            'results' => $result->results,
        ];
    }

    public function render(): View
    {
        $question = $this->currentQuestion()->load(['options', 'votes']);

        $template = $this->isHelperDriver()
            ? 'livewire.voting.voting-console-helper'
            : 'livewire.voting.voting-console';

        return view($template, [
            'currentQuestion' => $question,
            'questions' => $this->questions()->get(),
            'results' => $question->summarizedResults(),
            'codeLookup' => $this->getCodeLookup(),
            'codePrefixes' => $this->getCodePrefixes(),
        ])->layout('layouts.app')->title('Operátorská konzola');
    }

    /**
     * @return array<string, array{deviceNumber: string, buttonName: string}>
     */
    public function getCodeLookup(): array
    {
        $lookup = [];

        foreach ($this->devices as $device) {
            foreach ($device->codeMap() as $buttonName => $deviceCode) {
                if ($deviceCode === '') {
                    continue;
                }

                $lookup[$deviceCode] = [
                    'deviceNumber' => $device->device_number,
                    'buttonName' => $buttonName,
                ];
            }
        }

        return $lookup;
    }

    /**
     * @return array{oneByte: string[], twoBytes: string[]}
     */
    public function getCodePrefixes(): array
    {
        $oneBytePrefixes = [];
        $twoBytePrefixes = [];

        foreach (array_keys($this->getCodeLookup()) as $code) {
            $oneBytePrefixes[] = substr($code, 0, 2);
            $twoBytePrefixes[] = substr($code, 0, 4);
        }

        return [
            'oneByte' => array_values(array_unique($oneBytePrefixes)),
            'twoBytes' => array_values(array_unique($twoBytePrefixes)),
        ];
    }

    private function currentQuestion(): VotingQuestion
    {
        return $this->questions()
            ->whereKey($this->currentQuestionId)
            ->firstOrFail();
    }

    private function questions()
    {
        return $this->voting->questions()
            ->orderBy('order');
    }

    private function isQuestionActive(): bool
    {
        return $this->collectorEnabled || $this->timerRunning || $this->resultsVisible;
    }

    private function advanceToNextQuestion(): void
    {
        $nextQuestion = $this->questions()
            ->where('order', '>', $this->currentQuestion()->order)
            ->first();

        if (! $nextQuestion) {
            $this->markVotingFinished();

            return;
        }

        $this->selectQuestion($nextQuestion->id);
    }

    private function markVotingFinished(): void
    {
        if ($this->voting->finished_at !== null) {
            return;
        }

        // Don't touch voting.status — finishQuestion already set it to 'draft'
        // and the codebase only ever uses 'draft' / 'live'. Adding a third value
        // would be a behaviour change beyond the scope of this fix; finished_at
        // is the unambiguous signal.
        $this->voting->forceFill([
            'finished_at' => now(),
            'runtime_remaining_seconds' => 0,
            'runtime_timer_running' => false,
            'runtime_collector_enabled' => false,
            'runtime_results_visible' => false,
        ])->save();

        $this->remainingSeconds = 0;
        $this->timerRunning = false;
        $this->collectorEnabled = false;
        $this->resultsVisible = false;
        $this->dispatchConsoleState();
    }

    private function resetQuestionState(): void
    {
        $question = $this->currentQuestion();

        $this->remainingSeconds = $question->response_time_seconds;
        $this->timerRunning = false;
        $this->collectorEnabled = false;
        $this->resultsVisible = false;
        $this->advanceAfterResults = true;
        $this->lastVoteMessage = null;
        $this->lastMatchedDeviceNumber = null;
        $this->lastButtonName = null;
    }

    private function persistRuntimeState(): void
    {
        $this->voting->forceFill([
            'current_voting_question_id' => $this->currentQuestionId,
            'runtime_remaining_seconds' => $this->remainingSeconds,
            'runtime_timer_running' => $this->timerRunning,
            'runtime_collector_enabled' => $this->collectorEnabled,
            'runtime_results_visible' => $this->resultsVisible,
        ])->save();
    }

    private function dispatchConsoleState(): void
    {
        $this->dispatch(
            'console-state-updated',
            ...$this->consoleStatePayload(),
        );
    }

    /**
     * @return array{collectorEnabled: bool, timerRunning: bool, remainingSeconds: int, resultsVisible: bool}
     */
    private function consoleStatePayload(): array
    {
        return [
            'collectorEnabled' => $this->collectorEnabled,
            'timerRunning' => $this->timerRunning,
            'remainingSeconds' => $this->remainingSeconds,
            'resultsVisible' => $this->resultsVisible,
        ];
    }
}
