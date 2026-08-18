<?php

namespace App\Livewire\Election;

use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\ElectionCandidateAdmission;
use App\Models\ElectionContest;
use App\Models\Voting;
use App\Services\ElectionCandidateAdmissionManager;
use App\Support\PresentationRuntimeManager;
use App\Support\SerialAgentClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ElectionCandidateAdmissionConsole extends Component
{
    public Voting $voting;

    public Election $election;

    public string $firstName = '';

    public string $lastName = '';

    public ?int $deviceGroupId = null;

    public int $responseTimeSeconds = 30;

    public function mount(Voting $voting): void
    {
        abort_unless($voting->voting_type === 'election', 404);

        $this->voting = $voting;
        $this->election = $voting->election()->firstOrFail();
    }

    public function createAndOpenAdmission(): void
    {
        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'deviceGroupId' => ['nullable', 'integer'],
            'responseTimeSeconds' => ['required', 'integer', 'min:1', 'max:3600'],
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
            'response_time_seconds' => $validated['responseTimeSeconds'],
        ]);

        $this->reset('firstName', 'lastName', 'deviceGroupId');
        $this->responseTimeSeconds = 30;
        session()->flash('status', 'Návrh kandidáta bol pridaný.');
    }

    public function startAdmission(int $admissionId, ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime, SerialAgentClient $serialAgent): void
    {
        $admission = $this->admission($admissionId);
        $admissions->start($admission);
        $runtime->activate($this->voting, 'candidate_admission', ['admission_id' => $admission->id]);
        $serialAgent->command('start');
    }

    public function stopAdmission(int $admissionId, ElectionCandidateAdmissionManager $admissions, SerialAgentClient $serialAgent): void
    {
        $serialAgent->command('stop');
        $admissions->stop($this->admission($admissionId));
    }

    public function selectAdmission(int $admissionId, PresentationRuntimeManager $runtime): void
    {
        $admission = $this->admission($admissionId);
        $runtime->activate($this->voting, 'candidate_admission', ['admission_id' => $admission->id]);
    }

    public function liveTick(ElectionCandidateAdmissionManager $admissions, SerialAgentClient $serialAgent): void
    {
        $admission = ElectionCandidateAdmission::query()
            ->where('election_id', $this->election->id)
            ->where('status', 'live')
            ->first();

        if ($admission !== null
            && $admission->opened_at !== null
            && now()->greaterThanOrEqualTo($admission->opened_at->copy()->addSeconds($admission->response_time_seconds))) {
            $serialAgent->command('stop');
            $admissions->finish($admission);
        }
    }

    public function restartAdmission(int $admissionId, ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime, SerialAgentClient $serialAgent): void
    {
        $admission = $admissions->restart($this->admission($admissionId));
        $runtime->activate($this->voting, 'candidate_admission', ['admission_id' => $admission->id]);
        $serialAgent->command('start');
    }

    public function showAdmissionResults(int $admissionId, ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime): void
    {
        $admission = $admissions->showResults($this->admission($admissionId));
        $runtime->activate($this->voting, 'candidate_admission', ['admission_id' => $admission->id]);
    }

    public function updateAdmissionTime(int $admissionId, int $seconds): void
    {
        $admission = $this->admission($admissionId);
        if ($admission->status === 'live' || $seconds < 1 || $seconds > 3600) {
            return;
        }
        $admission->update(['response_time_seconds' => $seconds]);
    }

    public function updateAdmissionName(int $admissionId, string $firstName, string $lastName): void
    {
        $admission = $this->admission($admissionId);

        if ($admission->status === 'live' || trim($firstName) === '' || trim($lastName) === '') {
            return;
        }

        $admission->update(['first_name' => trim($firstName), 'last_name' => trim($lastName)]);
    }

    public function deleteAdmission(int $admissionId, PresentationRuntimeManager $runtime): void
    {
        $admission = $this->admission($admissionId);
        if ($admission->status === 'live') {
            return;
        }
        if ((int) ($runtime->current()->context['admission_id'] ?? 0) === $admission->id) {
            $runtime->clear();
        }
        $admission->delete();
    }

    public function resolveAdmission(int $admissionId, ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime): void
    {
        $admission = ElectionCandidateAdmission::query()
            ->where('election_id', $this->election->id)
            ->findOrFail($admissionId);

        $admission = $admissions->resolve($admission);
        $activeRuntime = $runtime->current();

        if ($activeRuntime->content_type === 'candidate_admission'
            && (int) ($activeRuntime->context['admission_id'] ?? 0) === $admission->id) {
            $runtime->clear();
        }

        session()->flash('status', $admission->status === 'accepted'
            ? 'Kandidát bol doplnený do kandidátky.'
            : 'Kandidát nebol doplnený.');
    }

    public function render(ElectionCandidateAdmissionManager $admissions, PresentationRuntimeManager $runtime): View
    {
        $activeRuntime = $runtime->current();
        $activeAdmission = $activeRuntime->content_type === 'candidate_admission'
            ? ElectionCandidateAdmission::query()
                ->with('contest')
                ->where('election_id', $this->election->id)
                ->find($activeRuntime->context['admission_id'] ?? 0)
            : null;
        $activeRemainingSeconds = $activeAdmission?->status === 'live' && $activeAdmission->opened_at !== null
            ? max(0, $activeAdmission->response_time_seconds - (now()->getTimestamp() - $activeAdmission->opened_at->getTimestamp()))
            : $activeAdmission?->response_time_seconds;

        return view('livewire.election.election-candidate-admission-console', [
            'groups' => $this->election->deviceGroups()->where('is_active', true)->get(),
            'admissions' => ElectionCandidateAdmission::query()->where('election_id', $this->election->id)->with('contest')->withCount('votes')->latest()->get(),
            'activeAdmission' => $activeAdmission,
            'activeResults' => $activeAdmission ? $admissions->summarizedResults($activeAdmission) : [],
            'activeRemainingSeconds' => $activeRemainingSeconds,
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

    private function admission(int $admissionId): ElectionCandidateAdmission
    {
        return ElectionCandidateAdmission::query()->where('election_id', $this->election->id)->findOrFail($admissionId);
    }
}
