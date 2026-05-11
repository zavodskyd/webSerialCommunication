<?php

declare(strict_types=1);

namespace App\Services\Voting;

use App\Models\Device;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Models\VotingAttendee;
use App\Models\VotingQuestion;
use App\Support\QomoHexFrameDecoder;
use InvalidArgumentException;

class VoteRecorder
{
    public function __construct(private readonly QomoHexFrameDecoder $frameDecoder) {}

    /**
     * Resolve a hex code to a vote, validate, and persist. Pure service —
     * no Livewire dependency, no UI state. Used by both:
     *
     *  - VotingConsole::recordVoteFromCode (Livewire path)
     *  - SerialFrameController (Node serial-helper IPC path)
     *
     * The $collectorEnabledHint parameter is the in-memory Livewire snapshot
     * of $voting->runtime_collector_enabled. The Livewire caller passes its
     * own value to avoid a DB round-trip on the hot path. The HTTP caller
     * has no in-memory state and passes `false`, falling through to a fresh
     * DB read.
     */
    public function record(
        string $code,
        Voting $voting,
        VotingQuestion $question,
        bool $collectorEnabledHint = false,
        string $source = 'web-serial',
    ): VoteRecordingResult {
        $result = $this->resolveResult($code, $voting, $question, $collectorEnabledHint);

        $this->logEvent(
            voting: $voting,
            question: $question,
            code: $code,
            result: $result,
            source: $source,
        );

        return $result;
    }

    private function resolveResult(
        string $code,
        Voting $voting,
        VotingQuestion $question,
        bool $collectorEnabledHint,
    ): VoteRecordingResult {
        if (! $collectorEnabledHint && ! $this->isCollectingVotes($voting, $question)) {
            return new VoteRecordingResult(
                accepted: false,
                message: 'Hlasovanie momentálne neprijíma hlasy.',
                deviceNumber: null,
                buttonName: null,
                results: $question->summarizedResults(),
                rejectionReason: 'collector_disabled',
            );
        }

        $decodedFrame = $this->frameDecoder->decode($code);

        if ($decodedFrame === null) {
            return new VoteRecordingResult(
                accepted: false,
                message: 'Kód '.$code.' sa nenašiel.',
                deviceNumber: null,
                buttonName: null,
                results: $question->summarizedResults(),
                rejectionReason: 'unknown_code',
            );
        }

        $device = $this->resolveDevice($decodedFrame['deviceNumber']);

        if ($device === null) {
            return new VoteRecordingResult(
                accepted: false,
                message: 'Zariadenie '.$decodedFrame['deviceNumber'].' sa nenašlo.',
                deviceNumber: (string) $decodedFrame['deviceNumber'],
                buttonName: $decodedFrame['buttonName'],
                results: $question->summarizedResults(),
                rejectionReason: 'unknown_device',
            );
        }

        $buttonName = $decodedFrame['buttonName'];

        if (! in_array($buttonName, ['A', 'B', 'C'], true)) {
            return new VoteRecordingResult(
                accepted: false,
                message: 'Tlačidlo '.$buttonName.' sa do výsledku hlasovania nezapočítava.',
                deviceNumber: $device->device_number,
                buttonName: $buttonName,
                results: $question->summarizedResults(),
                rejectionReason: 'non_voting_button',
            );
        }

        $attendee = VotingAttendee::query()->firstOrCreate(
            [
                'voting_id' => $voting->id,
                'device_id' => $device->id,
            ],
            [
                'weight' => 0,
                'is_present' => true,
                'can_vote' => true,
            ],
        );

        if ((float) $attendee->weight <= 0) {
            return new VoteRecordingResult(
                accepted: false,
                message: 'Zariadenie '.$device->device_number.' má nastavený počet hlasov 0.',
                deviceNumber: $device->device_number,
                buttonName: $buttonName,
                results: $question->summarizedResults(),
                rejectionReason: 'zero_weight',
            );
        }

        try {
            $question->recordVote($attendee, $buttonName);
        } catch (InvalidArgumentException $exception) {
            return new VoteRecordingResult(
                accepted: false,
                message: $exception->getMessage(),
                deviceNumber: $device->device_number,
                buttonName: $buttonName,
                results: $question->summarizedResults(),
                rejectionReason: 'record_failed',
            );
        }

        return new VoteRecordingResult(
            accepted: true,
            message: 'Zariadenie '.$device->device_number.' hlasovalo '.$buttonName.'.',
            deviceNumber: $device->device_number,
            buttonName: $buttonName,
            results: $question->summarizedResults(),
        );
    }

    private function logEvent(
        Voting $voting,
        VotingQuestion $question,
        string $code,
        VoteRecordingResult $result,
        string $source,
    ): void {
        $deviceId = $result->deviceNumber !== null
            ? Device::query()->where('device_number', $result->deviceNumber)->value('id')
            : null;

        VoteEvent::query()->create([
            'voting_id' => $voting->id,
            'voting_question_id' => $question->id,
            'device_id' => $deviceId,
            'raw_hex' => $code,
            'source' => $source,
            'button_name' => $result->buttonName,
            'accepted' => $result->accepted,
            'rejection_reason' => $result->rejectionReason,
            'received_at' => now(),
        ]);
    }

    private function isCollectingVotes(Voting $voting, VotingQuestion $question): bool
    {
        $voting->refresh();
        $question->refresh();

        return $voting->runtime_collector_enabled
            && in_array($question->status, ['live', 'paused'], true);
    }

    private function resolveDevice(int $deviceNumber): ?Device
    {
        $normalizedDeviceNumber = (string) $deviceNumber;

        return Device::query()
            ->whereIn('device_number', array_values(array_unique([
                $normalizedDeviceNumber,
                str_pad($normalizedDeviceNumber, 3, '0', STR_PAD_LEFT),
            ])))
            ->first();
    }
}
