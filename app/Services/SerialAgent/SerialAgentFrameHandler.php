<?php

declare(strict_types=1);

namespace App\Services\SerialAgent;

use App\Models\Voting;
use App\Services\ElectionCandidateAdmissionFrameRecorder;
use App\Services\ElectionRoundFrameRecorder;
use App\Services\Voting\VoteRecorder;
use App\Services\Voting\VoteRecordingResult;

class SerialAgentFrameHandler
{
    public function __construct(
        private readonly VoteRecorder $recorder,
        private readonly ElectionCandidateAdmissionFrameRecorder $admissionRecorder,
        private readonly ElectionRoundFrameRecorder $roundRecorder,
    ) {}

    public function handle(string $hex): ?VoteRecordingResult
    {
        $admissionResult = $this->admissionRecorder->recordIfActive($hex);

        if ($admissionResult !== null) {
            return $admissionResult;
        }

        $roundResult = $this->roundRecorder->recordIfActive($hex);
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
        );
    }
}
