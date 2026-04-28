<?php

namespace App\Livewire\Voting;

use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VotingEditor extends Component
{
    use WithFileUploads;

    public Voting $voting;

    #[Validate('required|string|min:3|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public ?string $title = null;

    #[Validate('nullable|string|max:2000')]
    public ?string $headerText = null;

    #[Validate('required|string|min:2|max:255')]
    public string $questionLabel = 'Hlasovanie';

    public ?string $logoPath = null;

    #[Validate('nullable|image|max:2048')]
    public ?TemporaryUploadedFile $logoUpload = null;

    #[Validate('required|integer|min:5|max:600')]
    public int $defaultResponseTimeSeconds = 30;

    public bool $autoShowResults = true;

    #[Validate('required|integer|min:1|max:200')]
    public int $generationCount = 10;

    #[Validate('required|integer|min:5|max:600')]
    public int $generationResponseTimeSeconds = 30;

    /**
     * @var array<int, array{id: int, order: int, text: string, response_time_seconds: int, status: string}>
     */
    public array $questionRows = [];

    /**
     * @var array<int, array{id: int, device_number: string, weight: string}>
     */
    public array $deviceWeightRows = [];

    #[Validate('nullable|file|max:1024')]
    public ?TemporaryUploadedFile $deviceWeightsImport = null;

    #[Validate('required|numeric|min:0|max:999999')]
    public string $bulkDeviceWeight = '1';

    #[Validate('required|integer|min:1|max:999999')]
    public int $bulkDeviceCount = 1;

    public function mount(Voting $voting): void
    {
        $this->voting = $voting;
        $this->fillFromVoting();
        $this->loadQuestions();
        $this->loadDeviceWeights();
    }

    public function saveVoting(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'headerText' => ['nullable', 'string', 'max:2000'],
            'questionLabel' => ['required', 'string', 'min:2', 'max:255'],
            'logoUpload' => ['nullable', 'image', 'max:2048'],
            'defaultResponseTimeSeconds' => ['required', 'integer', 'min:5', 'max:600'],
            'autoShowResults' => ['required', 'boolean'],
        ]);

        $logoPath = $this->logoPath;

        if ($validated['logoUpload'] ?? false) {
            if ($this->logoPath) {
                Storage::disk('public')->delete($this->logoPath);
            }

            $logoPath = $validated['logoUpload']->store('voting-logos', 'public');
            $this->logoPath = $logoPath;
            $this->logoUpload = null;
        }

        $this->voting->update([
            'name' => $validated['name'],
            'title' => $validated['title'],
            'header_text' => $validated['headerText'],
            'question_label' => $validated['questionLabel'],
            'logo_path' => $logoPath,
            'default_response_time_seconds' => $validated['defaultResponseTimeSeconds'],
            'auto_show_results' => $validated['autoShowResults'],
        ]);

        session()->flash('status', 'Hlasovanie bolo uložené.');
    }

    public function generateQuestions(): void
    {
        $validated = $this->validate([
            'questionLabel' => ['required', 'string', 'min:2', 'max:255'],
            'generationCount' => ['required', 'integer', 'min:1', 'max:200'],
            'generationResponseTimeSeconds' => ['required', 'integer', 'min:5', 'max:600'],
        ]);

        $this->voting->generateQuestions(
            baseLabel: $validated['questionLabel'],
            count: $validated['generationCount'],
            responseTimeSeconds: $validated['generationResponseTimeSeconds'],
        );

        $this->loadQuestions();
        session()->flash('status', 'Otázky boli vygenerované.');
    }

    public function addQuestion(): void
    {
        $nextOrder = ((int) $this->voting->questions()->max('order')) + 1;
        $label = trim($this->questionLabel).' '.$nextOrder;

        $this->voting->createQuestionWithDefaults(
            order: $nextOrder,
            label: $label,
            text: $label,
            responseTimeSeconds: $this->defaultResponseTimeSeconds,
        );

        $this->loadQuestions();
        session()->flash('status', 'Otázka bola pridaná.');
    }

    public function saveQuestion(int $questionId): void
    {
        $question = $this->findQuestion($questionId);
        $row = collect($this->questionRows)->firstWhere('id', $questionId);

        validator(
            ['row' => $row],
            [
                'row.order' => ['required', 'integer', 'min:1', 'max:999999'],
                'row.text' => ['required', 'string', 'max:2000'],
                'row.response_time_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            ],
            [],
            [
                'row.order' => 'poradie otázky',
                'row.text' => 'text otázky',
                'row.response_time_seconds' => 'čas odpovede',
            ],
        )->validate();

        DB::transaction(function () use ($question, $row): void {
            $this->moveQuestionToOrder($question, (int) $row['order']);

            $question->update([
                'text' => $row['text'],
                'response_time_seconds' => $row['response_time_seconds'],
            ]);
        });

        $this->loadQuestions();
        session()->flash('status', 'Otázka bola uložená.');
    }

    public function deleteQuestion(int $questionId): void
    {
        $this->findQuestion($questionId)->delete();
        $this->loadQuestions();

        session()->flash('status', 'Otázka bola vymazaná.');
    }

    public function saveDeviceWeights(): void
    {
        $validated = validator(
            ['rows' => $this->deviceWeightRows],
            [
                'rows' => ['array'],
                'rows.*.id' => ['required', 'integer', 'exists:devices,id'],
                'rows.*.weight' => ['required', 'numeric', 'min:0', 'max:999999'],
            ],
            [],
            [
                'rows.*.weight' => 'počet hlasov',
            ],
        )->validate();

        foreach ($validated['rows'] as $row) {
            VotingAttendee::query()->updateOrCreate(
                [
                    'voting_id' => $this->voting->id,
                    'device_id' => $row['id'],
                ],
                [
                    'weight' => $row['weight'],
                    'is_present' => true,
                    'can_vote' => true,
                    'registered_at' => now(),
                ],
            );
        }

        $this->loadDeviceWeights();
        session()->flash('status', 'Počty hlasov zariadení boli uložené.');
    }

    public function assignBulkDeviceWeights(): void
    {
        $validated = $this->validate([
            'bulkDeviceWeight' => ['required', 'numeric', 'min:0', 'max:999999'],
            'bulkDeviceCount' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $devices = Device::query()
            ->get()
            ->filter(fn (Device $device): bool => $this->deviceNumberIsInRange(
                deviceNumber: $device->device_number,
                maxDeviceNumber: $validated['bulkDeviceCount'],
            ));

        foreach ($devices as $device) {
            $this->updateDeviceWeight($device->id, $validated['bulkDeviceWeight']);
        }

        $this->loadDeviceWeights();
        session()->flash('status', 'Hromadné počty hlasov boli priradené '.count($devices).' zariadeniam.');
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
        return view('livewire.voting.voting-editor')
            ->layout('layouts.app')
            ->title('Editácia hlasovania');
    }

    private function fillFromVoting(): void
    {
        $this->name = $this->voting->name;
        $this->title = $this->voting->title;
        $this->headerText = $this->voting->header_text;
        $this->questionLabel = $this->voting->question_label ?? 'Hlasovanie';
        $this->logoPath = $this->voting->logo_path;
        $this->defaultResponseTimeSeconds = $this->voting->default_response_time_seconds ?? 30;
        $this->autoShowResults = $this->voting->auto_show_results ?? true;
        $this->generationResponseTimeSeconds = $this->defaultResponseTimeSeconds;
    }

    private function loadQuestions(): void
    {
        $this->voting->refresh();

        $this->questionRows = $this->voting->questions()
            ->orderBy('order')
            ->get()
            ->map(fn (VotingQuestion $question): array => [
                'id' => $question->id,
                'order' => $question->order,
                'text' => $question->text,
                'response_time_seconds' => $question->response_time_seconds,
                'status' => $question->status,
            ])
            ->all();
    }

    private function loadDeviceWeights(): void
    {
        $weightsByDeviceId = $this->voting->attendees()
            ->pluck('weight', 'device_id');

        $this->deviceWeightRows = Device::query()
            ->orderBy('device_number')
            ->get()
            ->map(fn (Device $device): array => [
                'id' => $device->id,
                'device_number' => $device->device_number,
                'weight' => (string) ($weightsByDeviceId[$device->id] ?? '0.00'),
            ])
            ->all();
    }

    private function findQuestion(int $questionId): VotingQuestion
    {
        return $this->voting->questions()
            ->whereKey($questionId)
            ->firstOrFail();
    }

    private function moveQuestionToOrder(VotingQuestion $question, int $targetOrder): void
    {
        $currentOrder = $question->order;

        if ($targetOrder === $currentOrder) {
            return;
        }

        $temporaryOrder = max(
            $targetOrder,
            (int) $this->voting->questions()->max('order'),
        ) + $question->id + 1000;

        $question->update(['order' => $temporaryOrder]);

        if ($targetOrder < $currentOrder) {
            $this->voting->questions()
                ->where('id', '!=', $question->id)
                ->whereBetween('order', [$targetOrder, $currentOrder - 1])
                ->reorder()
                ->orderByDesc('order')
                ->get()
                ->each(fn (VotingQuestion $affectedQuestion): bool => $affectedQuestion->update([
                    'order' => $affectedQuestion->order + 1,
                ]));
        } else {
            $this->voting->questions()
                ->where('id', '!=', $question->id)
                ->whereBetween('order', [$currentOrder + 1, $targetOrder])
                ->reorder()
                ->orderBy('order')
                ->get()
                ->each(fn (VotingQuestion $affectedQuestion): bool => $affectedQuestion->update([
                    'order' => $affectedQuestion->order - 1,
                ]));
        }

        $question->update(['order' => $targetOrder]);
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

    private function deviceNumberIsInRange(string $deviceNumber, int $maxDeviceNumber): bool
    {
        if (! ctype_digit($deviceNumber)) {
            return false;
        }

        $number = (int) ltrim($deviceNumber, '0');

        return $number >= 1 && $number <= $maxDeviceNumber;
    }
}
