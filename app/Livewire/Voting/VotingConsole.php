<?php

namespace App\Livewire\Voting;

use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
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

        if (! $this->collectorEnabled && ! $this->isQuestionCollectingVotes($question)) {
            $this->skipRender();

            return [
                'accepted' => false,
                'message' => 'Hlasovanie momentálne neprijíma hlasy.',
                'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
                'lastButtonName' => $this->lastButtonName,
                'results' => $question->summarizedResults(),
            ];
        }

        $device = $this->resolveDeviceByCode($code);

        if (! $device) {
            $this->lastVoteMessage = 'Kód '.$code.' sa nenašiel.';
            $this->skipRender();

            return [
                'accepted' => false,
                'message' => $this->lastVoteMessage,
                'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
                'lastButtonName' => $this->lastButtonName,
                'results' => $question->summarizedResults(),
            ];
        }

        $buttonName = $device->resolveButtonName($code);

        if (! in_array($buttonName, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
            $this->lastVoteMessage = 'Kód '.$code.' nepredstavuje hlasovaciu voľbu.';
            $this->skipRender();

            return [
                'accepted' => false,
                'message' => $this->lastVoteMessage,
                'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
                'lastButtonName' => $this->lastButtonName,
                'results' => $question->summarizedResults(),
            ];
        }

        $attendee = VotingAttendee::query()->firstOrCreate(
            [
                'voting_id' => $this->voting->id,
                'device_id' => $device->id,
            ],
            [
                'weight' => 0,
                'is_present' => true,
                'can_vote' => true,
            ],
        );

        if ((float) $attendee->weight <= 0) {
            $this->lastVoteMessage = 'Zariadenie '.$device->device_number.' má nastavený počet hlasov 0.';
            $this->skipRender();

            return [
                'accepted' => false,
                'message' => $this->lastVoteMessage,
                'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
                'lastButtonName' => $this->lastButtonName,
                'results' => $question->summarizedResults(),
            ];
        }

        try {
            $question->recordVote($attendee, $buttonName);
        } catch (\InvalidArgumentException $exception) {
            $this->lastVoteMessage = $exception->getMessage();
            $this->skipRender();

            return [
                'accepted' => false,
                'message' => $this->lastVoteMessage,
                'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
                'lastButtonName' => $this->lastButtonName,
                'results' => $question->summarizedResults(),
            ];
        }

        $this->lastMatchedDeviceNumber = $device->device_number;
        $this->lastButtonName = $buttonName;
        $this->lastVoteMessage = 'Zariadenie '.$device->device_number.' hlasovalo '.$buttonName.'.';
        $this->skipRender();

        return [
            'accepted' => true,
            'message' => $this->lastVoteMessage,
            'lastMatchedDeviceNumber' => $this->lastMatchedDeviceNumber,
            'lastButtonName' => $this->lastButtonName,
            'results' => $question->summarizedResults(),
        ];
    }

    public function render(): View
    {
        $question = $this->currentQuestion()->load(['options', 'votes']);

        return view('livewire.voting.voting-console', [
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

    private function isQuestionCollectingVotes(VotingQuestion $question): bool
    {
        $this->voting->refresh();
        $question->refresh();

        return $this->voting->runtime_collector_enabled
            && in_array($question->status, ['live', 'paused'], true);
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

    private function resolveDeviceByCode(string $code): ?Device
    {
        return $this->devices->first(function (Device $device) use ($code): bool {
            return in_array($code, $device->codeMap(), true);
        });
    }
}
