<?php

namespace App\Livewire\Election;

use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
use App\Models\Voting;
use App\Services\ElectionRoundManager;
use App\Support\PresentationRuntimeManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ElectionConsole extends Component
{
    public Voting $voting;

    public Election $election;

    public ?int $contestId = null;

    public ?int $roundId = null;

    public function mount(Voting $voting): void
    {
        abort_unless($voting->voting_type === 'election', 404);
        $this->voting = $voting;
        $this->election = $voting->election()->firstOrFail();
        $this->contestId = $this->election->contests()->value('id');
    }

    public function selectContest(int $contestId): void
    {
        $this->contestId = $contestId;
        $this->roundId = null;
    }

    public function createRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $round = $rounds->create($this->contest());
        $this->roundId = $round->id;
        $runtime->activate($this->voting, 'election_round', ['round_id' => $round->id]);
    }

    public function openRound(ElectionRoundManager $rounds, PresentationRuntimeManager $runtime): void
    {
        $round = $rounds->open($this->round());
        $runtime->activate($this->voting, 'election_round', ['round_id' => $round->id]);
    }

    public function closeRound(ElectionRoundManager $rounds): void
    {
        $rounds->close($this->round());
    }

    public function render(ElectionRoundManager $rounds): View
    {
        $contest = $this->contest();
        $round = $this->roundId ? $contest->rounds()->with('candidates')->find($this->roundId) : $contest->rounds()->with('candidates')->latest('round_number')->first();

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
