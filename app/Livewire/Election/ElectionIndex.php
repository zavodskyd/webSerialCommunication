<?php

namespace App\Livewire\Election;

use App\Models\Election;
use App\Models\Voting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ElectionIndex extends Component
{
    #[Validate('required|string|min:3|max:255')]
    public string $name = '';

    public bool $showAll = false;

    public function createElection(): void
    {
        $validated = $this->validate();

        $voting = DB::transaction(function () use ($validated): Voting {
            $voting = Voting::query()->create([
                'name' => $validated['name'],
                'voting_type' => 'election',
            ]);

            $election = Election::query()->create([
                'voting_id' => $voting->id,
            ]);

            $election->createDefaultContests();

            return $voting;
        });

        $this->redirectRoute('elections.edit', ['voting' => $voting]);
    }

    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;
    }

    public function archiveElection(int $votingId): void
    {
        Voting::query()
            ->whereKey($votingId)
            ->where('voting_type', 'election')
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function render(): View
    {
        $votings = Voting::query()
            ->where('voting_type', 'election')
            ->with(['election' => fn ($query) => $query->withCount(['contests', 'deviceGroups'])])
            ->when(! $this->showAll, fn ($query) => $query->whereNull('archived_at'))
            ->latest()
            ->get();

        return view('livewire.election.election-index', [
            'votings' => $votings,
        ])->layout('layouts.app')->title('Voľby');
    }
}
