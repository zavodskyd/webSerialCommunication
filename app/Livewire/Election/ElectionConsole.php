<?php

namespace App\Livewire\Election;

use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
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
        $this->contestId = $contest->id;
        $round = $contest->rounds()->reorder()->latest('round_number')->first();
        $this->roundId = $round?->id;
        $this->candidateId = null;
        $runtime->activate($this->voting, $round ? 'election_round' : 'election_contest', $round ? ['round_id' => $round->id] : ['contest_id' => $contest->id]);
    }

    public function createRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $round = $rounds->create($this->contest());
        $this->roundId = $round->id;
        $runtime->activate($this->voting, 'election_round', ['round_id' => $round->id]);
    }

    public function openRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        if (! $this->serialConnected) {
            session()->flash('status', 'Najprv pripojte Serial Agent.');

            return;
        }
        $round = $rounds->open($this->round());
        $runtime->activate($this->voting, 'election_round', ['round_id' => $round->id]);
    }

    public function closeRound(ElectionRoundManager $rounds): void
    {
        $closedRound = $rounds->close($this->round());
        $nextRound = $this->contest()->rounds()->reorder()->latest('round_number')->first();

        if ($nextRound !== null && $nextRound->id !== $closedRound->id) {
            $this->roundId = $nextRound->id;
            $this->candidateId = null;
        }
    }

    public function liveTick(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $round = $this->roundId ? $this->round() : null;

        if ($round === null || $round->status !== 'live' || $round->opened_at === null || now()->lessThan($round->opened_at->copy()->addSeconds($round->response_time_seconds))) {
            return;
        }

        $this->closeRound($rounds);
        $runtime->activate($this->voting, 'election_round', ['round_id' => $this->roundId]);
    }

    public function selectCandidate(int $candidateId, PresentationRuntimeManager $runtime): void
    {
        $candidate = $this->round()->candidates()->findOrFail($candidateId);
        $this->candidateId = $candidate->id;
        $runtime->activate($this->voting, 'election_round', ['round_id' => $this->roundId, 'candidate_id' => $candidate->id]);
    }

    public function render(ElectionRoundManager $rounds): View
    {
        $health = app(SerialAgentClient::class)->health();
        $this->serialAgentHealthy = (bool) ($health['ok'] ?? false);
        $this->serialConnected = (bool) ($health['connected'] ?? false);
        $contest = $this->contest();
        $round = $this->roundId ? $contest->rounds()->with('candidates')->find($this->roundId) : $contest->rounds()->with('candidates')->reorder()->latest('round_number')->first();

        return view('livewire.election.election-console', ['contests' => $this->election->contests()->get(), 'contest' => $contest, 'round' => $round, 'results' => $round ? $rounds->results($round) : null])->layout('layouts.app')->title('Volebná konzola');
    }

    private function contest(): ElectionContest
    {
        return $this->election->contests()->findOrFail($this->contestId);
    }

    private function round(): ElectionRound
    {
        return $this->contest()->rounds()->findOrFail($this->roundId);
    }
}
