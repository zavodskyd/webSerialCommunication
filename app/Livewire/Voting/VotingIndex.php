<?php

namespace App\Livewire\Voting;

use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingOption;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Validate;
use Livewire\Component;

class VotingIndex extends Component
{
    #[Validate('required|string|min:3|max:255')]
    public string $name = '';

    public bool $showAll = false;

    public function createVoting(): void
    {
        $validated = $this->validate();

        $voting = Voting::query()->create([
            'name' => $validated['name'],
        ]);

        $this->redirectRoute('votings.edit', ['voting' => $voting]);
    }

    public function copyVoting(int $votingId): void
    {
        $sourceVoting = Voting::query()
            ->with(['questions.options', 'attendees'])
            ->findOrFail($votingId);

        $copiedVoting = DB::transaction(function () use ($sourceVoting): Voting {
            $copiedVoting = Voting::query()->create([
                'name' => $sourceVoting->name.' - kópia',
                'status' => 'draft',
                'question_label' => $sourceVoting->question_label,
                'title' => $sourceVoting->title,
                'header_text' => $sourceVoting->header_text,
                'logo_path' => $sourceVoting->logo_path,
                'default_response_time_seconds' => $sourceVoting->default_response_time_seconds,
                'auto_show_results' => $sourceVoting->auto_show_results,
            ]);

            foreach ($sourceVoting->questions as $sourceQuestion) {
                $copiedQuestion = $copiedVoting->questions()->create([
                    'order' => $sourceQuestion->order,
                    'status' => 'draft',
                    'label' => $sourceQuestion->label,
                    'text' => $sourceQuestion->text,
                    'response_time_seconds' => $sourceQuestion->response_time_seconds,
                ]);

                $copiedQuestion->options()->createMany(
                    $sourceQuestion->options
                        ->map(fn (VotingOption $option): array => [
                            'key' => $option->key,
                            'label' => $option->label,
                            'color' => $option->color,
                            'sort_order' => $option->sort_order,
                        ])
                        ->all(),
                );
            }

            $copiedVoting->attendees()->createMany(
                $sourceVoting->attendees
                    ->map(fn (VotingAttendee $attendee): array => [
                        'device_id' => $attendee->device_id,
                        'weight' => $attendee->weight,
                        'is_present' => $attendee->is_present,
                        'can_vote' => $attendee->can_vote,
                        'registered_at' => $attendee->registered_at,
                    ])
                    ->all(),
            );

            return $copiedVoting;
        });

        $this->redirectRoute('votings.edit', ['voting' => $copiedVoting]);
    }

    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;
    }

    public function archiveVoting(int $votingId): void
    {
        Voting::query()
            ->whereKey($votingId)
            ->whereNull('archived_at')
            ->update([
                'archived_at' => now(),
            ]);
    }

    public function render(): View
    {
        $votingsQuery = Voting::query()
            ->withCount('questions')
            ->withCount([
                'questions as closed_questions_count' => fn ($query) => $query->where('status', 'closed'),
            ]);

        if (! $this->showAll) {
            $votingsQuery->whereNull('archived_at');
        }

        return view('livewire.voting.voting-index', [
            'votings' => $votingsQuery
                ->latest()
                ->get(),
        ])->layout('layouts.app')->title('Hlasovania');
    }
}
