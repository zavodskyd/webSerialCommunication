<?php

namespace App\Livewire\Voting;

use App\Models\ElectionCandidateAdmission;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
use App\Models\Voting;
use App\Models\VotingQuestion;
use App\Services\ElectionCandidateAdmissionManager;
use App\Services\ElectionRoundManager;
use App\Support\PresentationRuntimeManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class VotingPresentation extends Component
{
    public Voting $voting;

    public function mount(Voting $voting, ?VotingQuestion $question = null): void
    {
        $this->voting = $voting;

        if ($question !== null && $question->voting_id !== $this->voting->id) {
            abort(404);
        }

        if ($question !== null) {
            $this->voting->forceFill([
                'current_voting_question_id' => $question->id,
                'runtime_remaining_seconds' => $question->response_time_seconds,
            ])->save();
        }
    }

    public function render(PresentationRuntimeManager $runtime, ElectionCandidateAdmissionManager $admissions, ElectionRoundManager $rounds): View
    {
        $this->voting->refresh();

        $activeRuntime = $runtime->current();
        $admission = $activeRuntime->content_type === 'candidate_admission'
            ? ElectionCandidateAdmission::query()->with(['contest', 'election.voting'])->find($activeRuntime->context['admission_id'] ?? 0)
            : null;
        $round = $activeRuntime->content_type === 'election_round'
            ? ElectionRound::query()->with(['contest.election.voting', 'contest.rounds.candidates.votes', 'candidates'])->find($activeRuntime->context['round_id'] ?? 0)
            : null;
        $contest = $activeRuntime->content_type === 'election_contest'
            ? ElectionContest::query()->with(['election.voting', 'candidates'])->find($activeRuntime->context['contest_id'] ?? 0)
            : null;

        if ($admission !== null) {
            $this->voting = $admission->election->voting;
        } elseif ($round !== null) {
            $this->voting = $round->contest->election->voting;
        } elseif ($contest !== null) {
            $this->voting = $contest->election->voting;
        } elseif ($activeRuntime->content_type === 'standard_question' && $activeRuntime->voting_id !== null) {
            $this->voting = Voting::query()->findOrFail($activeRuntime->voting_id);
        }

        $question = $this->currentQuestion();

        $calculatedRoundResults = $round ? $rounds->results($round) : null;
        $roundResultsVisible = $round?->status === 'closed' && (bool) $this->voting->runtime_results_visible;
        $roundResults = $roundResultsVisible ? $calculatedRoundResults : null;
        $displayRoundCandidates = collect($calculatedRoundResults['candidates'] ?? [])
            ->map(fn (array $candidate): array => [...$candidate, 'prior_elected' => false]);

        if ($round !== null && ! $roundResultsVisible) {
            $displayRoundCandidates = $displayRoundCandidates
                ->map(fn (array $candidate): array => [
                    ...$candidate,
                    'weighted_total' => null,
                    'elected' => false,
                ])
                ->sortBy([
                    ['last_name', 'asc'],
                    ['first_name', 'asc'],
                ])->values();
        }

        if ($round !== null && $roundResultsVisible && $round->round_number > 1) {
            $priorElectedCandidates = $round->contest->rounds
                ->where('round_number', '<', $round->round_number)
                ->flatMap(fn (ElectionRound $previousRound) => $previousRound->candidates)
                ->where('status', 'elected')
                ->unique('election_candidate_id')
                ->map(fn ($candidate): array => [
                    'id' => $candidate->id,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'weighted_total' => (float) $candidate->votes->sum('weight_snapshot'),
                    'elected' => false,
                    'prior_elected' => true,
                ]);
            $displayRoundCandidates = $priorElectedCandidates->concat($displayRoundCandidates)->values();
        }

        return view('livewire.voting.voting-presentation', [
            'question' => $question,
            'participantCount' => $question?->votes()->distinct('device_id')->count('device_id') ?? 0,
            'results' => $question?->summarizedResults() ?? [],
            'maxResultValue' => $this->maxResultValue($question),
            'admission' => $admission,
            'admissionResults' => $admission ? $admissions->summarizedResults($admission) : [],
            'round' => $round,
            'roundResults' => $roundResults,
            'roundSeatLimit' => $calculatedRoundResults['remaining_seats'] ?? null,
            'roundAcceptedDeviceCount' => $calculatedRoundResults['accepted_device_count'] ?? 0,
            'roundResultsVisible' => $roundResultsVisible,
            'displayRoundCandidates' => $displayRoundCandidates->all(),
            'activeRoundCandidateId' => $activeRuntime->content_type === 'election_round' ? (int) ($activeRuntime->context['candidate_id'] ?? 0) : null,
            'contest' => $contest,
        ])->layout('layouts.presentation')->title('Prezentácia hlasovania');
    }

    private function currentQuestion(): ?VotingQuestion
    {
        $question = null;

        if ($this->voting->current_voting_question_id) {
            $question = $this->voting->currentQuestion()
                ->with(['options', 'votes'])
                ->first();
        }

        return $question ?? $this->voting->questions()
            ->with(['options', 'votes'])
            ->first();
    }

    private function maxResultValue(?VotingQuestion $question): float
    {
        if (! $question) {
            return 1.0;
        }

        return max(
            collect($question->summarizedResults())
                ->map(fn (array $result): float => (float) $result['weighted_total'])
                ->max() ?? 0,
            1,
        );
    }
}
