<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ElectionCandidateAdmission;
use App\Models\PresentationRuntime;
use App\Models\VoteEvent;
use App\Services\Voting\VoteRecordingResult;
use App\Support\PresentationRuntimeManager;
use App\Support\QomoHexFrameDecoder;
use InvalidArgumentException;

class ElectionCandidateAdmissionFrameRecorder
{
    public function __construct(
        private readonly ElectionCandidateAdmissionManager $admissions,
        private readonly PresentationRuntimeManager $runtime,
        private readonly QomoHexFrameDecoder $frameDecoder,
    ) {}

    public function recordIfActive(string $hex): ?VoteRecordingResult
    {
        $runtime = $this->runtime->current();

        if ($runtime->content_type !== 'candidate_admission') {
            return null;
        }

        $admission = $this->activeAdmission($runtime);

        if ($admission === null) {
            return $this->rejected('Aktívne doplnenie kandidáta sa nenašlo.', null, null, [], 'invalid_admission');
        }

        $decodedFrame = $this->frameDecoder->decode($hex);

        if ($decodedFrame === null) {
            return $this->logResult($admission, $hex, $this->rejected('Kód '.$hex.' sa nenašiel.', null, null, $this->admissions->summarizedResults($admission), 'unknown_code'));
        }

        $device = $this->resolveDevice($decodedFrame['deviceNumber']);

        if ($device === null) {
            return $this->logResult($admission, $hex, $this->rejected('Zariadenie '.$decodedFrame['deviceNumber'].' sa nenašlo.', (string) $decodedFrame['deviceNumber'], $decodedFrame['buttonName'], $this->admissions->summarizedResults($admission), 'unknown_device'));
        }

        if (! in_array($decodedFrame['buttonName'], ['A', 'B', 'C'], true)) {
            return $this->logResult($admission, $hex, $this->rejected('Tlačidlo '.$decodedFrame['buttonName'].' sa do výsledku nezapočítava.', $device->device_number, $decodedFrame['buttonName'], $this->admissions->summarizedResults($admission), 'non_voting_button'));
        }

        try {
            $this->admissions->recordVote($admission, $device, $decodedFrame['buttonName']);
        } catch (InvalidArgumentException $exception) {
            return $this->logResult($admission, $hex, $this->rejected($exception->getMessage(), $device->device_number, $decodedFrame['buttonName'], $this->admissions->summarizedResults($admission), 'record_failed'));
        }

        return $this->logResult($admission, $hex, new VoteRecordingResult(
            accepted: true,
            message: 'Zariadenie '.$device->device_number.' hlasovalo '.$decodedFrame['buttonName'].'.',
            deviceNumber: $device->device_number,
            buttonName: $decodedFrame['buttonName'],
            results: $this->admissions->summarizedResults($admission),
        ));
    }

    private function activeAdmission(PresentationRuntime $runtime): ?ElectionCandidateAdmission
    {
        $admissionId = $runtime->context['admission_id'] ?? null;

        if (! is_int($admissionId) && ! ctype_digit((string) $admissionId)) {
            return null;
        }

        $admission = ElectionCandidateAdmission::query()->with('election')->find($admissionId);

        if ($admission === null || $runtime->voting_id !== $admission->election->voting_id) {
            return null;
        }

        return $admission;
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

    /**
     * @param  array<int, array{key: string, label: string, color: ?string, vote_count: int, weighted_total: float}>  $results
     */
    private function rejected(string $message, ?string $deviceNumber, ?string $buttonName, array $results, string $reason): VoteRecordingResult
    {
        return new VoteRecordingResult(false, $message, $deviceNumber, $buttonName, $results, $reason);
    }

    private function logResult(ElectionCandidateAdmission $admission, string $hex, VoteRecordingResult $result): VoteRecordingResult
    {
        $deviceId = $result->deviceNumber !== null
            ? Device::query()->where('device_number', $result->deviceNumber)->value('id')
            : null;

        VoteEvent::query()->create([
            'voting_id' => $admission->election->voting_id,
            'device_id' => $deviceId,
            'raw_hex' => $hex,
            'source' => 'rust-agent',
            'button_name' => $result->buttonName,
            'accepted' => $result->accepted,
            'rejection_reason' => $result->rejectionReason,
            'received_at' => now(),
        ]);

        return $result;
    }
}
