<?php

declare(strict_types=1);

namespace App\Services\SerialAgent;

use App\Models\Voting;
use App\Services\ElectionCandidateAdmissionFrameRecorder;
use App\Services\ElectionRoundFrameRecorder;
use App\Services\Voting\VoteRecorder;
use App\Services\Voting\VoteRecordingResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SerialAgentFrameHandler
{
    public function __construct(
        private readonly VoteRecorder $recorder,
        private readonly ElectionCandidateAdmissionFrameRecorder $admissionRecorder,
        private readonly ElectionRoundFrameRecorder $roundRecorder,
    ) {}

    public function handleOnce(string $id, string $hex, ?CarbonImmutable $receivedAt = null): ?VoteRecordingResult
    {
        return DB::transaction(function () use ($id, $hex, $receivedAt): ?VoteRecordingResult {
            $inserted = DB::table('serial_agent_processed_frames')->insertOrIgnore([
                'id' => $id,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            return $this->handle($hex, $receivedAt);
        });
    }

    public function handle(string $hex, ?CarbonImmutable $receivedAt = null): ?VoteRecordingResult
    {
        $admissionResult = $this->admissionRecorder->recordIfActive($hex, $receivedAt);

        if ($admissionResult !== null) {
            return $admissionResult;
        }

        $roundResult = $this->roundRecorder->recordIfActive($hex, $receivedAt);
        if ($roundResult !== null) {
            return $roundResult;
        }

        $voting = Voting::query()
            ->whereNotNull('current_voting_question_id')
            ->where('runtime_collector_enabled', true)
            ->latest('updated_at')
            ->first();

        if ($voting === null) {
            return null;
        }

        $question = $voting->questions()->find($voting->current_voting_question_id);

        if ($question === null) {
            return null;
        }

        return $this->recorder->record(
            code: $hex,
            voting: $voting,
            question: $question,
            collectorEnabledHint: false,
            source: 'rust-agent',
            receivedAt: $receivedAt,
        );
    }
}
