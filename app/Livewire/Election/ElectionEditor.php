<?php

namespace App\Livewire\Election;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionContest;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ElectionEditor extends Component
{
    use WithFileUploads;

    public Voting $voting;

    public Election $election;

    #[Validate('required|string|min:3|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public ?string $title = null;

    #[Validate('nullable|string|max:2000')]
    public ?string $headerText = null;

    public ?string $logoPath = null;

    #[Validate('nullable|image|max:2048')]
    public $logoUpload = null;

    #[Validate('required|integer|min:5|max:600')]
    public int $defaultResponseTimeSeconds = 30;

    public bool $autoShowResults = true;

    public ?int $activeDeviceLimit = null;

    /** @var array<int, array{id: int, device_number: string, weight: string}> */
    public array $deviceWeightRows = [];

    #[Validate('nullable|file|max:1024')]
    public ?TemporaryUploadedFile $deviceWeightsImport = null;

    /**
     * @var array<int, array{id: int, name: string, seat_count: int, candidates: list<array{id: int, first_name: string, last_name: string}>}>
     */
    public array $contestRows = [];

    /**
     * @var array<int, array{first_name: string, last_name: string}>
     */
    public array $candidateDrafts = [];

    /**
     * @var list<array{id: ?int, name: string, is_active: bool, ranges: list<array{start_number: string, end_number: string}>}>
     */
    public array $groupRows = [];

    /** @var list<string> */
    public array $groupNameOptions = ['Hliny', 'Solinky', 'Vlčince', 'Rozptyl/Staré Mesto'];

    public function mount(Voting $voting): void
    {
        abort_unless($voting->voting_type === 'election', 404);

        $this->voting = $voting;
        $this->election = $voting->election()->firstOrFail();
        $this->fillFromVoting();
        $this->loadContests();
        $this->loadDeviceGroups();
        $this->loadDeviceWeights();
    }

    public function saveElection(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'headerText' => ['nullable', 'string', 'max:2000'],
            'logoUpload' => ['nullable', 'image', 'max:2048'],
            'defaultResponseTimeSeconds' => ['required', 'integer', 'min:5', 'max:600'],
            'autoShowResults' => ['required', 'boolean'],
        ]);

        $logoPath = $this->logoPath;

        if ($validated['logoUpload'] ?? false) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = $validated['logoUpload']->store('voting-logos', 'public');
            $this->logoPath = $logoPath;
            $this->logoUpload = null;
        }

        $this->voting->update([
            'name' => $validated['name'],
            'title' => $validated['title'],
            'header_text' => $validated['headerText'],
            'logo_path' => $logoPath,
            'default_response_time_seconds' => $validated['defaultResponseTimeSeconds'],
            'auto_show_results' => $validated['autoShowResults'],
        ]);

        $this->voting->refresh();

        session()->flash('status', 'Voľby boli uložené.');
    }

    public function addCandidate(int $contestId): void
    {
        $contest = $this->findContest($contestId);
        $draft = $this->candidateDrafts[$contestId] ?? [];

        $validated = validator(
            ['candidate' => $draft],
            [
                'candidate.first_name' => ['required', 'string', 'max:255'],
                'candidate.last_name' => ['required', 'string', 'max:255'],
            ],
            [],
            [
                'candidate.first_name' => 'meno kandidáta',
                'candidate.last_name' => 'priezvisko kandidáta',
            ],
        )->validate();

        $contest->candidates()->create([
            ...$validated['candidate'],
            'status' => 'approved',
        ]);

        $this->candidateDrafts[$contestId] = ['first_name' => '', 'last_name' => ''];
        $this->loadContests();
        $this->dispatch('focus-candidate-first-name', contestId: $contestId);
        session()->flash('status', 'Kandidát bol pridaný do súťaže.');
    }

    public function saveCandidate(int $candidateId): void
    {
        $candidate = $this->findCandidate($candidateId);

        foreach ($this->contestRows as $contest) {
            foreach ($contest['candidates'] as $candidateRow) {
                if ($candidateRow['id'] === $candidateId) {
                    $validated = validator(['candidate' => $candidateRow], [
                        'candidate.first_name' => ['required', 'string', 'max:255'],
                        'candidate.last_name' => ['required', 'string', 'max:255'],
                    ])->validate();

                    $candidate->update($validated['candidate']);
                    $this->loadContests();
                    session()->flash('status', 'Kandidát bol uložený.');

                    return;
                }
            }
        }

        abort(404);
    }

    public function removeCandidate(int $candidateId): void
    {
        $this->election->contests()
            ->whereHas('candidates', fn ($query) => $query->whereKey($candidateId))
            ->firstOrFail()
            ->candidates()
            ->whereKey($candidateId)
            ->delete();

        $this->loadContests();
        session()->flash('status', 'Kandidát bol odstránený.');
    }

    public function addDeviceGroup(): void
    {
        if ($this->availableGroupNames() === []) {
            $this->addError('groupRows', 'Všetky dostupné názvy skupín už boli pridané.');

            return;
        }

        $this->groupRows[] = [
            'id' => null,
            'name' => $this->availableGroupNames()[0],
            'is_active' => true,
            'range' => ['start_number' => '', 'end_number' => ''],
        ];
    }

    public function removeDeviceGroup(int $groupIndex): void
    {
        unset($this->groupRows[$groupIndex]);
        $this->groupRows = array_values($this->groupRows);
    }

    public function saveDeviceGroups(): void
    {
        $validated = validator(
            ['groupRows' => $this->groupRows],
            [
                'groupRows' => ['array'],
                'groupRows.*.id' => ['nullable', 'integer'],
                'groupRows.*.name' => ['required', 'string', 'max:255'],
                'groupRows.*.is_active' => ['required', 'boolean'],
                'groupRows.*.range.start_number' => ['required', 'integer', 'min:1'],
                'groupRows.*.range.end_number' => ['required', 'integer', 'min:1'],
            ],
            [],
            [
                'groupRows.*.name' => 'názov skupiny',
                'groupRows.*.range.start_number' => 'začiatok rozsahu',
                'groupRows.*.range.end_number' => 'koniec rozsahu',
            ],
        )->validate();

        if (! $this->rangesAreValidAndDisjoint($validated['groupRows'])) {
            return;
        }

        DB::transaction(function () use ($validated): void {
            $savedGroupIds = [];

            foreach ($validated['groupRows'] as $index => $groupRow) {
                $group = $this->election->deviceGroups()->updateOrCreate(
                    ['id' => $groupRow['id'] ?? null],
                    [
                        'name' => $groupRow['name'],
                        'sort_order' => $index + 1,
                        'is_active' => $groupRow['is_active'],
                    ],
                );

                $group->ranges()->delete();
                $group->ranges()->create($groupRow['range']);
                $savedGroupIds[] = $group->id;
            }

            $this->election->deviceGroups()
                ->when($savedGroupIds !== [], fn ($query) => $query->whereNotIn('id', $savedGroupIds))
                ->when($savedGroupIds === [], fn ($query) => $query)
                ->delete();
        });

        $this->loadDeviceGroups();
        session()->flash('status', 'Skupiny zariadení boli uložené.');
    }

    public function saveVotingWeights(): void
    {
        $validated = $this->validate([
            'activeDeviceLimit' => ['required', 'integer', 'min:1', 'max:9999'],
            'deviceWeightRows.*.id' => ['required', 'integer', 'exists:devices,id'],
            'deviceWeightRows.*.weight' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        DB::transaction(function () use ($validated): void {
            $this->election->update(['active_device_limit' => $validated['activeDeviceLimit']]);

            foreach ($validated['deviceWeightRows'] as $row) {
                VotingAttendee::query()->updateOrCreate(
                    ['voting_id' => $this->voting->id, 'device_id' => $row['id']],
                    ['weight' => $row['weight'], 'is_present' => true, 'can_vote' => true, 'registered_at' => now()],
                );
            }
        });

        $this->election->refresh();
        $this->loadDeviceWeights();
        session()->flash('status', 'Limit a váhy zariadení boli uložené.');
    }

    public function fillActiveDeviceWeights(): void
    {
        $validated = $this->validate(['activeDeviceLimit' => ['required', 'integer', 'min:1', 'max:9999']]);
        $limit = $validated['activeDeviceLimit'];

        foreach ($this->deviceWeightRows as $index => $row) {
            $number = (int) ltrim($row['device_number'], '0');
            if ($number >= 1 && $number <= $limit) {
                $this->deviceWeightRows[$index]['weight'] = '1';
            }
        }

        $this->election->update(['active_device_limit' => $limit]);
        session()->flash('status', 'Váha 1 bola vyplnená pre zariadenia 1 až '.$limit.'.');
    }

    public function importDeviceWeights(): void
    {
        $validated = $this->validate([
            'deviceWeightsImport' => ['required', 'file', 'max:1024'],
        ]);

        $handle = fopen($validated['deviceWeightsImport']->getRealPath(), 'r');

        if ($handle === false) {
            $this->addError('deviceWeightsImport', 'Súbor sa nepodarilo otvoriť.');

            return;
        }

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $deviceNumber = trim((string) ($row[0] ?? ''));
            $weight = trim((string) ($row[1] ?? ''));

            if ($deviceNumber === 'device_number' && $weight === 'weight') {
                continue;
            }

            if ($deviceNumber === '' || $weight === '' || ! is_numeric($weight)) {
                continue;
            }

            $device = Device::query()
                ->where('device_number', $deviceNumber)
                ->first();

            if (! $device) {
                continue;
            }

            $this->updateDeviceWeight($device->id, $weight);
            $imported++;
        }

        fclose($handle);

        $this->deviceWeightsImport = null;
        $this->loadDeviceWeights();

        session()->flash('status', 'Importované počty hlasov pre '.$imported.' zariadení.');
    }

    public function exportDeviceWeights(): StreamedResponse
    {
        $this->loadDeviceWeights();

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, ['device_number', 'weight']);

            foreach ($this->deviceWeightRows as $row) {
                fputcsv($output, [$row['device_number'], $row['weight']]);
            }

            fclose($output);
        }, Str::slug($this->voting->name).'-vahy-zariadeni.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        return view('livewire.election.election-editor')
            ->layout('layouts.app')
            ->title('Editácia volieb');
    }

    private function fillFromVoting(): void
    {
        $this->name = $this->voting->name;
        $this->title = $this->voting->title;
        $this->headerText = $this->voting->header_text;
        $this->logoPath = $this->voting->logo_path;
        $this->defaultResponseTimeSeconds = $this->voting->default_response_time_seconds ?? 30;
        $this->autoShowResults = $this->voting->auto_show_results ?? true;
    }

    private function loadContests(): void
    {
        $this->contestRows = $this->election->contests()
            ->with('candidates')
            ->get()
            ->map(function (ElectionContest $contest): array {
                $this->candidateDrafts[$contest->id] ??= ['first_name' => '', 'last_name' => ''];

                return [
                    'id' => $contest->id,
                    'name' => $contest->name,
                    'seat_count' => $contest->seat_count,
                    'candidates' => $contest->candidates
                        ->map(fn (ElectionCandidate $candidate): array => [
                            'id' => $candidate->id,
                            'first_name' => $candidate->first_name,
                            'last_name' => $candidate->last_name,
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    private function loadDeviceGroups(): void
    {
        $this->groupRows = $this->election->deviceGroups()
            ->with('ranges')
            ->get()
            ->map(fn (DeviceGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'is_active' => $group->is_active,
                'range' => [
                    'start_number' => (string) $group->ranges->first()?->start_number,
                    'end_number' => (string) $group->ranges->first()?->end_number,
                ],
            ])
            ->all();
    }

    private function loadDeviceWeights(): void
    {
        $this->activeDeviceLimit = $this->election->active_device_limit;
        $weights = $this->voting->attendees()->pluck('weight', 'device_id');
        $this->deviceWeightRows = Device::query()->ordered()->get()->map(fn (Device $device): array => [
            'id' => $device->id,
            'device_number' => $device->device_number,
            'weight' => (string) ($weights[$device->id] ?? '0.00'),
        ])->all();
    }

    private function updateDeviceWeight(int $deviceId, string|int|float $weight): void
    {
        VotingAttendee::query()->updateOrCreate(
            [
                'voting_id' => $this->voting->id,
                'device_id' => $deviceId,
            ],
            [
                'weight' => $weight,
                'is_present' => true,
                'can_vote' => true,
                'registered_at' => now(),
            ],
        );
    }

    private function findContest(int $contestId): ElectionContest
    {
        return $this->election->contests()->whereKey($contestId)->firstOrFail();
    }

    private function findCandidate(int $candidateId): ElectionCandidate
    {
        return ElectionCandidate::query()
            ->whereKey($candidateId)
            ->whereHas('contest', fn ($query) => $query->where('election_id', $this->election->id))
            ->firstOrFail();
    }

    /** @return list<string> */
    public function availableGroupNames(): array
    {
        return array_values(array_diff($this->groupNameOptions, array_column($this->groupRows, 'name')));
    }

    /**
     * @param  list<array{id: ?int, name: string, is_active: bool, ranges: list<array{start_number: int, end_number: int}>}>  $groups
     */
    private function rangesAreValidAndDisjoint(array $groups): bool
    {
        $ranges = [];

        foreach ($groups as $groupIndex => $group) {
            $range = $group['range'];
            if ($range['start_number'] > $range['end_number']) {
                $this->addError("groupRows.{$groupIndex}.range.end_number", 'Koniec rozsahu musí byť väčší alebo rovný začiatku.');

                return false;
            }

            $ranges[] = [
                'start_number' => $range['start_number'],
                'end_number' => $range['end_number'],
                'group_index' => $groupIndex,
            ];
        }

        usort($ranges, fn (array $left, array $right): int => $left['start_number'] <=> $right['start_number']);

        for ($index = 1; $index < count($ranges); $index++) {
            if ($ranges[$index]['start_number'] <= $ranges[$index - 1]['end_number']) {
                $this->addError(
                    "groupRows.{$ranges[$index]['group_index']}.range.start_number",
                    'Rozsahy zariadení sa nesmú prekrývať.',
                );

                return false;
            }
        }

        return true;
    }
}
