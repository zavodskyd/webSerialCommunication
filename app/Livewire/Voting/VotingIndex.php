<?php

namespace App\Livewire\Voting;

use App\Models\Voting;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class VotingIndex extends Component
{
    #[Validate('required|string|min:3|max:255')]
    public string $name = '';

    public function createVoting(): void
    {
        $validated = $this->validate();

        $voting = Voting::query()->create([
            'name' => $validated['name'],
        ]);

        $this->redirectRoute('votings.edit', ['voting' => $voting]);
    }

    public function render(): View
    {
        return view('livewire.voting.voting-index', [
            'votings' => Voting::query()
                ->withCount('questions')
                ->withCount([
                    'questions as closed_questions_count' => fn ($query) => $query->where('status', 'closed'),
                ])
                ->latest()
                ->get(),
        ])->layout('layouts.app')->title('Hlasovania');
    }
}
