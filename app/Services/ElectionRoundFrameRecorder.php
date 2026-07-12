<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Services\Voting\VoteRecordingResult;
use App\Support\PresentationRuntimeManager;
use App\Support\QomoHexFrameDecoder;

class ElectionRoundFrameRecorder
{
    public function __construct(private readonly ElectionRoundManager $rounds, private readonly PresentationRuntimeManager $runtime, private readonly QomoHexFrameDecoder $decoder) {}

    public function recordIfActive(string $hex): ?VoteRecordingResult
    {
        $runtime = $this->runtime->current();
        if ($runtime->content_type !== 'election_round') {
            return null;
        }
        $round = ElectionRound::query()->find($runtime->context['round_id'] ?? 0);
        $candidate = ElectionRoundCandidate::query()->find($runtime->context['candidate_id'] ?? 0);
        $decoded = $this->decoder->decode($hex);
        if (! $round || ! $candidate || ! $decoded || $decoded['buttonName'] !== 'A') {
            return new VoteRecordingResult(false, 'Tento rámec sa do voľby kandidáta nezapočítava.', null, $decoded['buttonName'] ?? null, []);
        }
        $device = Device::query()->whereIn('device_number', [(string) $decoded['deviceNumber'], str_pad((string) $decoded['deviceNumber'], 3, '0', STR_PAD_LEFT)])->first();
        if (! $device) {
            return new VoteRecordingResult(false, 'Zariadenie sa nenašlo.', (string) $decoded['deviceNumber'], 'A', []);
        }
        $this->rounds->recordVote($round, $candidate, $device);

        return new VoteRecordingResult(true, 'Hlas bol prijatý.', $device->device_number, 'A', []);
    }
}
