<?php

namespace App\Services;

use App\Exceptions\ElectionVoteRejected;
use App\Models\Device;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Models\VoteEvent;
use App\Services\Voting\VoteRecordingResult;
use App\Support\PresentationRuntimeManager;
use App\Support\QomoHexFrameDecoder;
use Carbon\CarbonImmutable;

class ElectionRoundFrameRecorder
{
    public function __construct(private readonly ElectionRoundManager $rounds, private readonly PresentationRuntimeManager $runtime, private readonly QomoHexFrameDecoder $decoder) {}

    public function recordIfActive(string $hex, ?CarbonImmutable $receivedAt = null): ?VoteRecordingResult
    {
        $runtime = $this->runtime->current();
        if ($runtime->content_type !== 'election_round') {
            return null;
        }
        $round = ElectionRound::query()->find($runtime->context['round_id'] ?? 0);
        $candidate = ElectionRoundCandidate::query()->find($runtime->context['candidate_id'] ?? 0);
        $decoded = $this->decoder->decode($hex);
        $deadline = $round?->opened_at?->copy()->addSeconds($round->response_time_seconds);

        if ($receivedAt !== null && $deadline !== null && $receivedAt->greaterThan($deadline)) {
            return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(false, 'Hlas prišiel po skončení časového limitu.', $decoded === null ? null : (string) $decoded['deviceNumber'], $decoded['buttonName'] ?? null, [], 'after_deadline'), $receivedAt);
        }
        if (! $round || ! $candidate || ! $round->contest->election->voting->runtime_collector_enabled || ! $decoded || $decoded['buttonName'] !== 'A') {
            return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(false, 'Tento rámec sa do voľby kandidáta nezapočítava.', null, $decoded['buttonName'] ?? null, [], 'non_voting_button'), $receivedAt);
        }
        $device = Device::query()->whereIn('device_number', [(string) $decoded['deviceNumber'], str_pad((string) $decoded['deviceNumber'], 3, '0', STR_PAD_LEFT)])->first();
        if (! $device) {
            return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(false, 'Zariadenie sa nenašlo.', (string) $decoded['deviceNumber'], 'A', [], 'unknown_device'), $receivedAt);
        }
        try {
            $this->rounds->recordVote($round, $candidate, $device);
        } catch (ElectionVoteRejected $exception) {
            return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(false, $exception->getMessage(), $device->device_number, 'A', [], $exception->reason), $receivedAt);
        } catch (\InvalidArgumentException $exception) {
            return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(false, $exception->getMessage(), $device->device_number, 'A', [], 'record_failed'), $receivedAt);
        }

        return $this->logResult($round, $candidate, $hex, new VoteRecordingResult(true, 'Hlas bol prijatý.', $device->device_number, 'A', []), $receivedAt);
    }

    private function logResult(?ElectionRound $round, ?ElectionRoundCandidate $candidate, string $hex, VoteRecordingResult $result, ?CarbonImmutable $receivedAt = null): VoteRecordingResult
    {
        if ($round === null) {
            return $result;
        }

        VoteEvent::query()->create([
            'voting_id' => $round->contest->election->voting_id,
            'election_round_id' => $round->id,
            'election_round_candidate_id' => $candidate?->id,
            'device_id' => $result->deviceNumber === null ? null : Device::query()->where('device_number', $result->deviceNumber)->value('id'),
            'raw_hex' => $hex,
            'source' => 'rust-agent',
            'button_name' => $result->buttonName,
            'accepted' => $result->accepted,
            'rejection_reason' => $result->rejectionReason,
            'received_at' => $receivedAt ?? now(),
        ]);

        return $result;
    }
}
