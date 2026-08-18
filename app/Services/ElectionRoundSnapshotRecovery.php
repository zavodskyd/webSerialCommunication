<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ElectionRound;
use App\Models\Voting;
use App\Models\VotingAttendee;
use Illuminate\Support\Facades\DB;

class ElectionRoundSnapshotRecovery
{
    public function recoverEmptyLiveRounds(Voting $voting): int
    {
        return DB::transaction(function () use ($voting): int {
            $rounds = ElectionRound::query()
                ->where('status', 'live')
                ->whereHas('contest.election', fn ($query) => $query->where('voting_id', $voting->id))
                ->whereDoesntHave('votes')
                ->whereDoesntHave('eligibleDeviceWeights')
                ->lockForUpdate()
                ->get();

            if ($rounds->isEmpty()) {
                return 0;
            }

            $eligibleAttendees = VotingAttendee::query()
                ->where('voting_id', $voting->id)
                ->where('is_present', true)
                ->where('can_vote', true)
                ->where('weight', '>=', 1)
                ->get(['device_id', 'weight']);

            if ($eligibleAttendees->isEmpty()) {
                return 0;
            }

            foreach ($rounds as $round) {
                $round->eligibleDeviceWeights()->createMany($eligibleAttendees->map(
                    fn (VotingAttendee $attendee): array => [
                        'device_id' => $attendee->device_id,
                        'weight_snapshot' => $attendee->weight,
                    ],
                )->all());
                $round->update(['eligible_weight_total' => (float) $eligibleAttendees->sum('weight')]);
            }

            return $rounds->count();
        });
    }
}
