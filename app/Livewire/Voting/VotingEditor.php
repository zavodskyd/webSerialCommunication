<?php

namespace App\Livewire\Voting;

use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
                'row.text' => ['required', 'string', 'max:2000'],
                'row.response_time_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            ],
            [],
            [
                'row.text' => 'text otázky',
                'row.response_time_seconds' => 'čas odpovede',
            ],
        )->validate();

        $question->update([
            'text' => $row['text'],
            'response_time_seconds' => $row['response_time_seconds'],
        ]);

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
}
