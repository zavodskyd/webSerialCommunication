<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ElectionContest;
use App\Models\ElectionRound;
use App\Models\ElectionRoundCandidate;
use App\Models\ElectionRoundVote;
use App\Models\VotingAttendee;
use Illuminate\Support\Facades\DB;

class ElectionRoundManager
{
    public function create(ElectionContest $contest, int $responseTimeSeconds = 30): ElectionRound
    {
        return DB::transaction(function () use ($contest, $responseTimeSeconds): ElectionRound {
            $contest = ElectionContest::query()->with('candidates')->lockForUpdate()->findOrFail($contest->id);
            $round = $contest->rounds()->create([
                'round_number' => ((int) $contest->rounds()->max('round_number')) + 1,
                'response_time_seconds' => $responseTimeSeconds,
            ]);

            $round->candidates()->createMany($contest->candidates->values()->map(
                fn ($candidate, int $index): array => [
                    'election_candidate_id' => $candidate->id,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'sort_order' => $index + 1,
                ],
            )->all());

            return $round->refresh();
        });
    }

    public function open(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== 'draft') {
                throw new \InvalidArgumentException('Kolo nie je pripravené na spustenie.');
            }
            $round->update(['status' => 'live', 'opened_at' => now(), 'closed_at' => null]);

            return $round->refresh();
        });
    }

    public function close(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== 'live') {
                throw new \InvalidArgumentException('Kolo neprebieha.');
            }
            $round->update(['status' => 'closed', 'closed_at' => now()]);

            return $round->refresh();
        });
    }

    public function recordVote(ElectionRound $round, ElectionRoundCandidate $candidate, Device $device): ElectionRoundVote
    {
        return DB::transaction(function () use ($round, $candidate, $device): ElectionRoundVote {
            $round = ElectionRound::query()->with('contest.election')->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== 'live' || $candidate->election_round_id !== $round->id) {
                throw new \InvalidArgumentException('Kolo neprijíma tento hlas.');
            }
            $attendee = VotingAttendee::query()
                ->where('voting_id', $round->contest->election->voting_id)
                ->where('device_id', $device->id)
                ->firstOrFail();
            if (! $attendee->can_vote || ! $attendee->is_present || (float) $attendee->weight <= 0) {
                throw new \InvalidArgumentException('Zariadenie nemá platnú váhu hlasu.');
            }

            $existingVotes = ElectionRoundVote::query()
                ->where('election_round_id', $round->id)
                ->where('device_id', $device->id);
            if ($round->contest->key === 'chairperson') {
                $firstVote = $existingVotes->first();
                if ($firstVote !== null) {
                    return $firstVote;
                }
            } elseif ($existingVotes->where('election_round_candidate_id', '!=', $candidate->id)->count() >= $round->contest->seat_count) {
                throw new \InvalidArgumentException('Zariadenie už podporilo maximálny počet kandidátov v tomto kole.');
            }

            return ElectionRoundVote::query()->updateOrCreate(
                ['election_round_candidate_id' => $candidate->id, 'device_id' => $device->id],
                ['election_round_id' => $round->id, 'weight_snapshot' => $attendee->weight, 'voted_at' => now()],
            );
        });
    }

    /**
     * @return array{total_weight: float, majority_threshold: float, candidates: array<int, array{id: int, first_name: string, last_name: string, weighted_total: float, elected: bool}>}
     */
    public function results(ElectionRound $round): array
    {
        $round->loadMissing('candidates.votes');
        $totalWeight = (float) $round->votes()->sum('weight_snapshot');
        $threshold = floor($totalWeight / 2) + 1;
        $eligible = $round->candidates
            ->map(fn ($candidate): array => [
                'id' => $candidate->id,
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'weighted_total' => (float) $candidate->votes->sum('weight_snapshot'),
            ])
            ->sortBy([
                ['weighted_total', 'desc'],
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->values();
        $seatCount = $round->contest()->value('seat_count');
        $electedIds = $eligible->filter(fn (array $candidate): bool => $candidate['weighted_total'] >= $threshold)
            ->take($seatCount)
            ->pluck('id')
            ->all();

        return [
            'total_weight' => $totalWeight,
            'majority_threshold' => $threshold,
            'candidates' => $eligible->map(fn (array $candidate): array => [...$candidate, 'elected' => in_array($candidate['id'], $electedIds, true)])->all(),
        ];
    }
}
