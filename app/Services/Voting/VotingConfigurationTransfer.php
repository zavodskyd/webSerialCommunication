<?php

namespace App\Services\Voting;

use App\Models\Device;
use App\Models\Election;
use App\Models\Voting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VotingConfigurationTransfer
{
    public const FORMAT = 'serial-communication.configuration';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function export(Voting $voting): array
    {
        $voting->refresh();
        $votingType = $voting->voting_type ?: 'standard';

        if (! in_array($votingType, ['standard', 'election'], true)) {
            throw new InvalidArgumentException('Tento typ záznamu nie je možné exportovať.');
        }

        $voting->load(['attendees.device']);

        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'type' => $votingType === 'election' ? 'election' : 'voting',
            'name' => $voting->name,
            'settings' => [
                'question_label' => $voting->question_label,
                'title' => $voting->title,
                'header_text' => $voting->header_text,
                'default_response_time_seconds' => $voting->default_response_time_seconds,
                'auto_show_results' => $voting->auto_show_results,
            ],
            'logo' => $this->exportLogo($voting->logo_path),
            'attendees' => $voting->attendees
                ->filter(fn ($attendee): bool => $attendee->device !== null)
                ->map(fn ($attendee): array => [
                    'device_number' => (string) $attendee->device->device_number,
                    'weight' => (string) $attendee->weight,
                    'is_present' => (bool) $attendee->is_present,
                    'can_vote' => (bool) $attendee->can_vote,
                ])
                ->values()
                ->all(),
        ];

        if ($votingType === 'standard') {
            $voting->load(['questions.options']);
            $payload['questions'] = $voting->questions->map(fn ($question): array => [
                'order' => $question->order,
                'label' => $question->label,
                'text' => $question->text,
                'response_time_seconds' => $question->response_time_seconds,
                'options' => $question->options->map(fn ($option): array => [
                    'key' => $option->key,
                    'label' => $option->label,
                    'color' => $option->color,
                    'sort_order' => $option->sort_order,
                ])->values()->all(),
            ])->values()->all();

            return $payload;
        }

        $voting->load(['election.deviceGroups.ranges', 'election.contests.candidates']);
        $election = $voting->election;

        if ($election === null) {
            throw new InvalidArgumentException('Voľby nemajú pripravenú konfiguráciu.');
        }

        $payload['election'] = [
            'weight_one_device_count' => $election->weight_one_device_count,
            'quorum_participant_count' => $election->quorum_participant_count,
        ];
        $payload['device_groups'] = $election->deviceGroups->map(fn ($group): array => [
            'reference' => $group->sort_order,
            'name' => $group->name,
            'sort_order' => $group->sort_order,
            'is_active' => $group->is_active,
            'quorum_participant_count' => $group->quorum_participant_count,
            'ranges' => $group->ranges->map(fn ($range): array => [
                'start_number' => $range->start_number,
                'end_number' => $range->end_number,
            ])->values()->all(),
        ])->values()->all();
        $payload['contests'] = $election->contests->map(fn ($contest): array => [
            'key' => $contest->key,
            'name' => $contest->name,
            'seat_count' => $contest->seat_count,
            'sort_order' => $contest->sort_order,
            'device_group_reference' => $contest->deviceGroup?->sort_order,
            'candidates' => $contest->candidates->map(fn ($candidate): array => [
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'status' => $candidate->status,
            ])->values()->all(),
        ])->values()->all();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function import(array $payload, string $expectedType, ?Voting $target = null): Voting
    {
        $validated = $this->validatePayload($payload, $expectedType);

        if ($target !== null && $target->voting_type !== ($expectedType === 'election' ? 'election' : 'standard')) {
            throw new InvalidArgumentException('Konfiguráciu nie je možné importovať do zvoleného typu záznamu.');
        }

        $newLogoPath = $this->storeLogo($validated['logo']);
        $oldLogoPath = $target?->logo_path;

        try {
            $voting = DB::transaction(function () use ($validated, $expectedType, $target, $newLogoPath): Voting {
                $voting = $target ?? new Voting;
                $name = $target?->name ?? $validated['name'];

                if ($target !== null) {
                    $this->clearExistingConfiguration($target);
                }

                $voting->fill([
                    'name' => $name,
                    'voting_type' => $expectedType === 'election' ? 'election' : 'standard',
                    'status' => 'draft',
                    'question_label' => $validated['settings']['question_label'],
                    'title' => $validated['settings']['title'],
                    'header_text' => $validated['settings']['header_text'],
                    'logo_path' => $newLogoPath,
                    'default_response_time_seconds' => $validated['settings']['default_response_time_seconds'],
                    'auto_show_results' => $validated['settings']['auto_show_results'],
                    'current_voting_question_id' => null,
                    'runtime_remaining_seconds' => 0,
                    'runtime_timer_running' => false,
                    'runtime_collector_enabled' => false,
                    'runtime_results_visible' => false,
                    'started_at' => null,
                    'finished_at' => null,
                    'archived_at' => null,
                ]);
                $voting->save();

                $this->importAttendees($voting, $validated['attendees']);

                if ($expectedType === 'election') {
                    $this->importElection($voting, $validated);
                } else {
                    $this->importQuestions($voting, $validated['questions']);
                }

                return $voting->fresh();
            });
        } catch (\Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($oldLogoPath !== null && $oldLogoPath !== $newLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return $voting;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, string $expectedType): array
    {
        if (! in_array($expectedType, ['voting', 'election'], true)) {
            throw new InvalidArgumentException('Neznámy typ konfigurácie.');
        }

        if (($payload['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Súbor nie je podporovaná konfigurácia hlasovania alebo volieb.');
        }

        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Verzia konfigurácie nie je podporovaná.');
        }

        if (($payload['type'] ?? null) !== $expectedType) {
            throw new InvalidArgumentException('Typ importovanej konfigurácie nezodpovedá cieľu.');
        }

        $rules = [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'settings' => ['required', 'array'],
            'settings.question_label' => ['required', 'string', 'min:2', 'max:255'],
            'settings.title' => ['nullable', 'string', 'max:255'],
            'settings.header_text' => ['nullable', 'string', 'max:2000'],
            'settings.default_response_time_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            'settings.auto_show_results' => ['required', 'boolean'],
            'logo' => ['present', 'nullable', 'array'],
            'logo.mime' => ['required_with:logo', 'string'],
            'logo.data' => ['required_with:logo', 'string'],
            'attendees' => ['required', 'array'],
            'attendees.*.device_number' => ['required', 'string', 'max:255', 'distinct'],
            'attendees.*.weight' => ['required', 'numeric', 'min:0'],
            'attendees.*.is_present' => ['required', 'boolean'],
            'attendees.*.can_vote' => ['required', 'boolean'],
        ];

        if ($expectedType === 'voting') {
            $rules += [
                'questions' => ['required', 'array'],
                'questions.*.order' => ['required', 'integer', 'min:1', 'distinct'],
                'questions.*.label' => ['nullable', 'string', 'max:255'],
                'questions.*.text' => ['required', 'string', 'max:5000'],
                'questions.*.response_time_seconds' => ['required', 'integer', 'min:5', 'max:600'],
                'questions.*.options' => ['required', 'array', 'min:1'],
                'questions.*.options.*.key' => ['required', 'string', 'max:10'],
                'questions.*.options.*.label' => ['required', 'string', 'max:255'],
                'questions.*.options.*.color' => ['nullable', 'string', 'max:20'],
                'questions.*.options.*.sort_order' => ['required', 'integer', 'min:1'],
            ];
        } else {
            $rules += [
                'election' => ['required', 'array'],
                'election.weight_one_device_count' => ['nullable', 'integer', 'min:0'],
                'election.quorum_participant_count' => ['nullable', 'integer', 'min:0'],
                'device_groups' => ['required', 'array'],
                'device_groups.*.reference' => ['required', 'integer', 'min:1', 'distinct'],
                'device_groups.*.name' => ['required', 'string', 'max:255'],
                'device_groups.*.sort_order' => ['required', 'integer', 'min:1', 'distinct'],
                'device_groups.*.is_active' => ['required', 'boolean'],
                'device_groups.*.quorum_participant_count' => ['nullable', 'integer', 'min:0'],
                'device_groups.*.ranges' => ['required', 'array'],
                'device_groups.*.ranges.*.start_number' => ['required', 'integer', 'min:1'],
                'device_groups.*.ranges.*.end_number' => ['required', 'integer', 'min:1'],
                'contests' => ['required', 'array'],
                'contests.*.key' => ['required', 'string', 'max:255', 'distinct'],
                'contests.*.name' => ['required', 'string', 'max:255'],
                'contests.*.seat_count' => ['required', 'integer', 'min:1'],
                'contests.*.sort_order' => ['required', 'integer', 'min:1', 'distinct'],
                'contests.*.device_group_reference' => ['nullable', 'integer'],
                'contests.*.candidates' => ['required', 'array'],
                'contests.*.candidates.*.first_name' => ['required', 'string', 'max:255'],
                'contests.*.candidates.*.last_name' => ['required', 'string', 'max:255'],
                'contests.*.candidates.*.status' => ['required', 'string', 'max:50'],
            ];
        }

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($payload, $expectedType): void {
            if ($expectedType !== 'election') {
                return;
            }

            foreach ($payload['device_groups'] ?? [] as $groupIndex => $group) {
                foreach ($group['ranges'] ?? [] as $rangeIndex => $range) {
                    if (($range['end_number'] ?? 0) < ($range['start_number'] ?? 0)) {
                        $validator->errors()->add(
                            "device_groups.{$groupIndex}.ranges.{$rangeIndex}.end_number",
                            'Koncové číslo rozsahu nesmie byť menšie ako počiatočné číslo.'
                        );
                    }
                }
            }

            $references = collect($payload['device_groups'] ?? [])->pluck('reference');
            foreach ($payload['contests'] ?? [] as $index => $contest) {
                $reference = $contest['device_group_reference'] ?? null;
                if ($reference !== null && ! $references->contains($reference)) {
                    $validator->errors()->add("contests.{$index}.device_group_reference", 'Súťaž odkazuje na neexistujúcu skupinu zariadení.');
                }
            }
        });

        if ($validator->fails()) {
            throw new InvalidArgumentException('Konfiguračný súbor má neplatnú alebo neúplnú štruktúru: '.$validator->errors()->first());
        }

        return $validator->validated();
    }

    private function clearExistingConfiguration(Voting $voting): void
    {
        $voting->update(['current_voting_question_id' => null]);
        DB::table('presentation_runtimes')->where('voting_id', $voting->id)->delete();
        DB::table('vote_events')->where('voting_id', $voting->id)->delete();
        $voting->questions()->delete();
        $voting->attendees()->delete();
        $voting->election()->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $attendees
     */
    private function importAttendees(Voting $voting, array $attendees): void
    {
        $devices = Device::query()
            ->whereIn('device_number', collect($attendees)->pluck('device_number'))
            ->get()
            ->keyBy(fn (Device $device): string => (string) $device->device_number);

        foreach ($attendees as $attendee) {
            $device = $devices->get((string) $attendee['device_number']);
            if ($device === null) {
                continue;
            }

            $voting->attendees()->create([
                'device_id' => $device->id,
                'weight' => $attendee['weight'],
                'is_present' => $attendee['is_present'],
                'can_vote' => $attendee['can_vote'],
                'registered_at' => null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function importQuestions(Voting $voting, array $questions): void
    {
        foreach ($questions as $questionData) {
            $question = $voting->questions()->create([
                'order' => $questionData['order'],
                'status' => 'draft',
                'label' => $questionData['label'],
                'text' => $questionData['text'],
                'response_time_seconds' => $questionData['response_time_seconds'],
                'opened_at' => null,
                'closed_at' => null,
            ]);
            $question->options()->createMany($questionData['options']);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function importElection(Voting $voting, array $validated): void
    {
        $election = Election::query()->create([
            'voting_id' => $voting->id,
            'status' => 'preparation',
            'weight_one_device_count' => $validated['election']['weight_one_device_count'],
            'quorum_participant_count' => $validated['election']['quorum_participant_count'],
            'candidate_admissions_locked' => false,
            'started_at' => null,
            'finished_at' => null,
        ]);

        $groupsByReference = [];
        foreach ($validated['device_groups'] as $groupData) {
            $group = $election->deviceGroups()->create([
                'name' => $groupData['name'],
                'sort_order' => $groupData['sort_order'],
                'is_active' => $groupData['is_active'],
                'quorum_participant_count' => $groupData['quorum_participant_count'],
            ]);
            $group->ranges()->createMany($groupData['ranges']);
            $groupsByReference[$groupData['reference']] = $group;
        }

        foreach ($validated['contests'] as $contestData) {
            $groupReference = $contestData['device_group_reference'];
            $contest = $election->contests()->create([
                'device_group_id' => $groupReference === null ? null : $groupsByReference[$groupReference]->id,
                'key' => $contestData['key'],
                'name' => $contestData['name'],
                'seat_count' => $contestData['seat_count'],
                'sort_order' => $contestData['sort_order'],
            ]);
            $contest->candidates()->createMany($contestData['candidates']);
        }
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function exportLogo(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            throw new InvalidArgumentException('Logo záznamu sa nepodarilo nájsť.');
        }

        return [
            'mime' => $disk->mimeType($path) ?: 'application/octet-stream',
            'data' => base64_encode($disk->get($path)),
        ];
    }

    /**
     * @param  array{mime: string, data: string}|null  $logo
     */
    private function storeLogo(?array $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        $contents = base64_decode($logo['data'], true);
        if ($contents === false || strlen($contents) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Logo v konfigurácii je neplatné alebo presahuje 2 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (! isset($extensions[$mime]) || $mime !== $logo['mime']) {
            throw new InvalidArgumentException('Logo v konfigurácii nemá podporovaný obrazový formát.');
        }

        $path = 'voting-logos/'.Str::uuid().'.'.$extensions[$mime];
        if (! Storage::disk('public')->put($path, $contents)) {
            throw new InvalidArgumentException('Logo z konfigurácie sa nepodarilo uložiť.');
        }

        return $path;
    }
}
