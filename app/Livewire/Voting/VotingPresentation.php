<?php

namespace App\Livewire\Voting;

use App\Models\Voting;
use App\Models\VotingQuestion;
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

    public function render(): View
    {
        $this->voting->refresh();

        $question = $this->currentQuestion();

        return view('livewire.voting.voting-presentation', [
            'question' => $question,
            'participantCount' => $question?->votes()->distinct('device_id')->count('device_id') ?? 0,
            'results' => $question?->summarizedResults() ?? [],
            'maxResultValue' => $this->maxResultValue($question),
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
