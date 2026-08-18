<?php

namespace App\Livewire\Election;

use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Models\Voting;
use App\Services\ElectionRoundManager;
use App\Support\PresentationRuntimeManager;
use App\Support\SerialAgentClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ElectionConsole extends Component
{
    public Voting $voting;

    public Election $election;

    public ?int $contestId = null;

    public ?int $roundId = null;

    public ?int $candidateId = null;

    public int $remainingSeconds = 0;

    public bool $timerRunning = false;

    public bool $collectorEnabled = false;

    public bool $resultsVisible = false;

    public bool $serialConnected = false;

    public ?bool $serialAgentHealthy = null;

    public function mount(Voting $voting): void
    {
        abort_unless($voting->voting_type === 'election', 404);

        $this->voting = $voting;
        $this->election = $voting->election()->firstOrFail();
        $this->contestId = $this->election->contests()->value('id');
        $this->selectContest($this->contestId, app(PresentationRuntimeManager::class));
    }

    public function selectContest(int $contestId, PresentationRuntimeManager $runtime): void
    {
        $contest = $this->election->contests()->findOrFail($contestId);
        $round = $contest->rounds()->reorder()->latest('round_number')->first();

        $this->contestId = $contest->id;
        $this->roundId = $round?->id;
        $this->selectFirstCandidate($round);
        $this->resetCandidateState($round);
        $this->activatePresentation($runtime);
    }

    public function createRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $round = $rounds->create(
            $this->contest(),
            $this->voting->default_response_time_seconds ?? 30,
        );

        $this->roundId = $round->id;
        $this->selectFirstCandidate($round);
        $this->resetCandidateState($round);
        $this->activatePresentation($runtime);
    }

    public function startRoundViaHelper(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        if (! $this->serialConnected) {
            session()->flash('status', 'Najprv pripojte Serial Agent.');

            return;
        }

        $shouldStartCollector = ! $this->collectorEnabled;
        $this->startRound($rounds, $runtime);

        if ($shouldStartCollector) {
            app(SerialAgentClient::class)->command('start');
        }
    }

    public function startRoundPausedViaHelper(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $this->startRoundViaHelper($rounds, $runtime);

        if ($this->collectorEnabled && $this->timerRunning) {
            $this->pauseRound(recalculateRemaining: false);
        }
    }

    public function pauseRoundViaHelper(): void
    {
        $this->pauseRound();
    }

    public function stopRoundViaHelper(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        if (! $this->collectorEnabled) {
            return;
        }

        app(SerialAgentClient::class)->command('stop');
        $this->finishCurrentCandidate($rounds, $runtime);
    }

    public function showRoundResults(PresentationRuntimeManager $runtime): void
    {
        $round = $this->round();
        if ($round->status !== 'closed' || $this->collectorEnabled) {
            return;
        }

        $this->resultsVisible = true;
        $this->candidateId = null;
        $this->persistRuntimeState();
        $this->activatePresentation($runtime);
    }

    public function hideRoundResults(PresentationRuntimeManager $runtime): void
    {
        $this->resultsVisible = false;
        $this->persistRuntimeState();
        $this->activatePresentation($runtime);
    }

    public function advanceToNextRound(PresentationRuntimeManager $runtime): void
    {
        if ($this->round()?->status !== 'closed') {
            return;
        }

        $nextRound = $this->contest()->rounds()->reorder()->latest('round_number')->first();
        if ($nextRound === null || $nextRound->id === $this->roundId) {
            return;
        }

        $this->roundId = $nextRound->id;
        $this->selectFirstCandidate($nextRound);
        $this->resetCandidateState($nextRound);
        $this->activatePresentation($runtime);
    }

    public function liveTick(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        if (! $this->collectorEnabled || ! $this->timerRunning || $this->roundId === null) {
            return;
        }

        $round = $this->round();
        if ($round->opened_at === null) {
            return;
        }

        $this->remainingSeconds = max(0, $round->response_time_seconds - $this->elapsedSecondsSince($round->opened_at));

        if ($this->remainingSeconds <= 0) {
            app(SerialAgentClient::class)->command('stop');
            $this->finishCurrentCandidate($rounds, $runtime, 0);

            return;
        }

        $this->persistRuntimeState();
    }

    public function selectCandidate(int $candidateId, PresentationRuntimeManager $runtime): void
    {
        if ($this->collectorEnabled || $this->resultsVisible) {
            return;
        }

        $candidate = $this->round()->candidates()->findOrFail($candidateId);
        $this->candidateId = $candidate->id;
        $this->resetCandidateState($this->round());
        $this->activatePresentation($runtime);
    }

    public function render(ElectionRoundManager $rounds): View
    {
        $health = app(SerialAgentClient::class)->health();
        $this->serialAgentHealthy = (bool) ($health['ok'] ?? false);
        $this->serialConnected = (bool) ($health['connected'] ?? false);

        $contest = $this->contest();
        $round = $this->roundId ? $contest->rounds()->with('candidates')->find($this->roundId) : null;
        $nextRound = $round?->status === 'closed'
            ? $contest->rounds()->reorder()->latest('round_number')->first()
            : null;

        return view('livewire.election.election-console', [
            'contests' => $this->election->contests()->get(),
            'contest' => $contest,
            'round' => $round,
            'results' => $round ? $rounds->results($round) : null,
            'hasNextRound' => $nextRound !== null && $nextRound->id !== $round?->id,
        ])->layout('layouts.app')->title('Volebná konzola');
    }

    private function startRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        if ($this->collectorEnabled && ! $this->timerRunning) {
            $this->resumeRound();

            return;
        }

        $round = $this->round();
        if ($round->status === 'draft') {
            $round = $rounds->open($round);
        }

        if ($round->status !== 'live') {
            return;
        }

        if ($this->candidateId === null) {
            $this->selectFirstCandidate($round);
        }

        $round->update(['opened_at' => now()]);
        $this->remainingSeconds = $round->response_time_seconds;
        $this->timerRunning = true;
        $this->collectorEnabled = true;
        $this->resultsVisible = false;
        $this->voting->update(['status' => 'live', 'started_at' => $this->voting->started_at ?? now(), 'finished_at' => null]);
        $this->persistRuntimeState();
        $this->activatePresentation($runtime);
    }

    private function pauseRound(bool $recalculateRemaining = true): void
    {
        if (! $this->collectorEnabled) {
            return;
        }

        $round = $this->round();
        if ($recalculateRemaining && $this->timerRunning && $round->opened_at !== null) {
            $this->remainingSeconds = max(0, $round->response_time_seconds - $this->elapsedSecondsSince($round->opened_at));
        }

        $this->timerRunning = false;
        $this->persistRuntimeState();
    }

    private function resumeRound(): void
    {
        $round = $this->round();
        $consumed = max(0, $round->response_time_seconds - $this->remainingSeconds);

        $round->update(['opened_at' => now()->startOfSecond()->subSeconds($consumed)]);
        $this->timerRunning = true;
        $this->persistRuntimeState();
    }

    private function finishCurrentCandidate(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime, ?int $remainingSeconds = null): void
    {
        $round = $this->round();
        if ($remainingSeconds !== null) {
            $this->remainingSeconds = max(0, $remainingSeconds);
        }

        $nextCandidate = $this->nextCandidate($round);
        $this->timerRunning = false;
        $this->collectorEnabled = false;

        if ($nextCandidate !== null) {
            $this->candidateId = $nextCandidate->id;
            $this->resetCandidateState($round);
            $this->persistRuntimeState();
            $this->activatePresentation($runtime);

            return;
        }

        $rounds->close($round);
        $this->candidateId = null;
        $this->remainingSeconds = 0;
        $this->resultsVisible = true;
        $this->voting->update(['status' => 'draft']);
        $this->persistRuntimeState();
        $this->activatePresentation($runtime);
    }

    private function selectFirstCandidate(?ElectionRound $round): void
    {
        $this->candidateId = $round?->candidates()->orderBy('sort_order')->value('id');
    }

    private function nextCandidate(ElectionRound $round): ?ElectionRoundCandidate
    {
        $candidate = $this->candidateId
            ? $round->candidates()->find($this->candidateId)
            : null;

        return $candidate
            ? $round->candidates()->where('sort_order', '>', $candidate->sort_order)->orderBy('sort_order')->first()
            : null;
    }

    private function resetCandidateState(?ElectionRound $round): void
    {
        $this->remainingSeconds = $round?->response_time_seconds ?? 0;
        $this->timerRunning = false;
        $this->collectorEnabled = false;
        $this->resultsVisible = false;
        $this->persistRuntimeState();
    }

    private function activatePresentation(PresentationRuntimeManager $runtime): void
    {
        $runtime->activate($this->voting, $this->roundId ? 'election_round' : 'election_contest', $this->roundId
            ? array_filter(['round_id' => $this->roundId, 'candidate_id' => $this->candidateId])
            : ['contest_id' => $this->contestId]);
    }

    private function persistRuntimeState(): void
    {
        $this->voting->forceFill([
            'runtime_remaining_seconds' => $this->remainingSeconds,
            'runtime_timer_running' => $this->timerRunning,
            'runtime_collector_enabled' => $this->collectorEnabled,
            'runtime_results_visible' => $this->resultsVisible,
        ])->save();
    }

    private function elapsedSecondsSince(\DateTimeInterface $startedAt): int
    {
        return max(0, now()->getTimestamp() - $startedAt->getTimestamp());
    }

    private function contest(): ElectionContest
    {
        return $this->election->contests()->findOrFail($this->contestId);
    }

    private function round(): ?ElectionRound
    {
        return $this->roundId ? $this->contest()->rounds()->find($this->roundId) : null;
    }
}
