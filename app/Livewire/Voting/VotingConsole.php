<?php

namespace App\Livewire\Voting;

use App\Models\Device;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingQuestion;
use App\Services\Voting\VoteRecorder;
use App\Support\SerialAgentClient;
use App\Support\SerialAgentMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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

    public bool $eventsLogVisible = false;

    public bool $serialConnected = false;

    public ?string $connectedPortPath = null;

    public ?bool $serialAgentHealthy = null;

    public int $serialAgentQueuedFrames = 0;

    public int $lastSerialAgentHealthAt = 0;

    private const SERIAL_AGENT_HEALTH_INTERVAL_SECONDS = 2;

    public function mount(Voting $voting, ?VotingQuestion $question = null): void
    {
        SerialAgentMode::clear();

        $this->voting = $voting;
        $this->devices = Device::query()
            ->ordered()
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
    }

    public function connectSerial(): void
    {
        $this->lastVoteMessage = 'Serial zariadenie vyber a pripoj v okne Serial Agent.';
    }

    public function disconnectSerial(): void
    {
        if ($this->collectorEnabled) {
            return;
        }

        app(SerialAgentClient::class)->command('close');

        $this->serialConnected = false;
        $this->connectedPortPath = null;
    }

    public function startQuestionViaHelper(): void
    {
        $payload = $this->startQuestion();

        if (($payload['collectorEnabled'] ?? false) === true) {
            $this->startNativeSerialCollection();
        }
    }

    public function startQuestionPausedViaHelper(): void
    {
        $payload = $this->startQuestion();

        if (($payload['collectorEnabled'] ?? false) === true) {
            $this->startNativeSerialCollection();
            $this->pauseQuestion(recalculateRemaining: false);
        }
    }

    public function pauseQuestionViaHelper(): void
    {
        $this->pauseQuestion();
    }

    public function finishQuestionViaHelper(): void
    {
        app(SerialAgentClient::class)->command('stop');

        $this->finishQuestion();
    }

    public function liveTick(): void
    {
        $this->refreshLastVoteFromEvents();
        $this->probeSerialAgentHealthIfDue();

        if (! $this->collectorEnabled || ! $this->timerRunning) {
            return;
        }

        $question = $this->currentQuestion();

        if ($question->opened_at === null) {
            return;
        }

        $elapsed = $this->elapsedSecondsSince($question->opened_at);
        $this->remainingSeconds = max(0, $question->response_time_seconds - $elapsed);

        if ($this->remainingSeconds <= 0) {
            app(SerialAgentClient::class)->command('stop');

            $this->finishQuestion(0);

            return;
        }

        if ($this->voting->runtime_remaining_seconds !== $this->remainingSeconds) {
            $this->persistRuntimeState();
        }
    }

    private function probeSerialAgentHealthIfDue(): void
    {
        $now = time();

        if ($now - $this->lastSerialAgentHealthAt < self::SERIAL_AGENT_HEALTH_INTERVAL_SECONDS) {
            return;
        }

        $this->lastSerialAgentHealthAt = $now;

        $response = app(SerialAgentClient::class)->health();

        $this->serialAgentHealthy = (bool) ($response['ok'] ?? false);
        $this->serialConnected = (bool) ($response['connected'] ?? $this->serialConnected);
        $this->connectedPortPath = is_string($response['selected_port'] ?? null)
            ? $response['selected_port']
            : $this->connectedPortPath;
        $this->serialAgentQueuedFrames = (int) ($response['queued_frames'] ?? 0);
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

    public function pauseQuestion(bool $recalculateRemaining = true): void
    {
        if (! $this->collectorEnabled) {
            return;
        }

        $question = $this->currentQuestion();

        if ($recalculateRemaining && $this->timerRunning && $question->opened_at !== null) {
            $elapsed = $this->elapsedSecondsSince($question->opened_at);
            $this->remainingSeconds = max(0, $question->response_time_seconds - $elapsed);
        }

        $this->timerRunning = false;

        $question->update([
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

        $question = $this->currentQuestion();

        // Shift opened_at so "elapsed = response_time_seconds - remainingSeconds"
        // holds at this instant. Without this, paused wall-clock time gets counted
        // and the timer skips ahead on resume.
        $consumed = max(0, $question->response_time_seconds - $this->remainingSeconds);

        $question->update([
            'status' => 'live',
            'opened_at' => now()->startOfSecond()->subSeconds($consumed),
        ]);

        $this->timerRunning = true;

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

    public function toggleEventsLog(): void
    {
        $this->eventsLogVisible = ! $this->eventsLogVisible;
    }

    public function render(): View
    {
        $question = $this->currentQuestion()->load(['options', 'votes']);

        return view('livewire.voting.voting-console-helper', [
            'currentQuestion' => $question,
            'questions' => $this->questions()->get(),
            'results' => $question->summarizedResults(),
            'eventsLog' => $this->eventsLogVisible ? $this->recentEventsForCurrentVoting() : collect(),
        ])->layout('layouts.app')->title('Operátorská konzola');
    }

    /**
     * All vote_events for the current question in this voting, newest first.
     * Powers the "Zobraziť log hlasov" diagnostic modal in the operator console.
     */
    private function recentEventsForCurrentVoting(): Collection
    {
        return VoteEvent::query()
            ->where('voting_id', $this->voting->id)
            ->where('voting_question_id', $this->currentQuestionId)
            ->latest('received_at')
            ->with(['device:id,device_number', 'votingQuestion:id,order'])
            ->get();
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

    private function startNativeSerialCollection(): void
    {
        SerialAgentMode::clear();

        app(SerialAgentClient::class)->command('start');
    }

    private function elapsedSecondsSince(\DateTimeInterface $startedAt): int
    {
        return max(0, now()->getTimestamp() - $startedAt->getTimestamp());
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
