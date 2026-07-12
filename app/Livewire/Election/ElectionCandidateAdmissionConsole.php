<?php

namespace App\Livewire\Election;

use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\ElectionContest;
use App\Models\Voting;
use App\Services\ElectionCandidateAdmissionManager;
use App\Support\PresentationRuntimeManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ElectionCandidateAdmissionConsole extends Component
{
    public Voting $voting;

    public Election $election;

    public string $firstName = '';

    public string $lastName = '';

    public ?int $deviceGroupId = null;

    public function mount(Voting $voting): void
    {
        abort_unless($voting->voting_type === 'election', 404);

        $this->voting = $voting;
        $this->election = $voting->election()->firstOrFail();
    }

    public function createAndOpenAdmission(ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime): void
    {
        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'deviceGroupId' => ['nullable', 'integer'],
        ]);

        $group = $validated['deviceGroupId']
            ? $this->election->deviceGroups()->whereKey($validated['deviceGroupId'])->firstOrFail()
            : null;
        $contest = $this->contestForAdmission($group);

        $admission = ElectionCandidateAdmission::query()->create([
            'election_id' => $this->election->id,
            'election_contest_id' => $contest->id,
            'device_group_id' => $group?->id,
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
        ]);

        $admission = $admissions->open($admission);
        $runtime->activate($this->voting, 'candidate_admission', ['admission_id' => $admission->id]);

        $this->reset('firstName', 'lastName', 'deviceGroupId');
        session()->flash('status', 'Doplnenie kandidáta je otvorené.');
    }

    public function render(): View
    {
        return view('livewire.election.election-candidate-admission-console', [
            'groups' => $this->election->deviceGroups()->where('is_active', true)->get(),
            'admissions' => ElectionCandidateAdmission::query()->where('election_id', $this->election->id)->latest()->get(),
        ])->layout('layouts.app')->title('Doplnenie kandidáta');
    }

    private function contestForAdmission(?DeviceGroup $group): ElectionContest
    {
        $key = $group ? match ($group->name) {
            'Hliny' => 'board-hliny',
            'Solinky' => 'board-solinky',
            'Vlčince' => 'board-vlcince',
            'Rozptyl/Staré Mesto' => 'board-rozptyl-stare-mesto',
            default => throw new \InvalidArgumentException('Lokalita nemá priradenú volebnú súťaž.'),
        } : 'supervisory-committee';

        return $this->election->contests()->where('key', $key)->firstOrFail();
    }
}
