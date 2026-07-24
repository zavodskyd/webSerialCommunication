<?php

namespace App\Services;

use App\Exceptions\ElectionVoteRejected;
use App\Models\Device;
use App\Models\ElectionCandidate;
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
            $quorumParticipantCount = $round->contest->election->quorum_participant_count;
            if (($quorumParticipantCount ?? 0) < 1) {
                throw new \InvalidArgumentException('Pred spustením nastavte celkový počet účastníkov pre základ väčšiny.');
            }
            $eligibleAttendees = VotingAttendee::query()
                ->where('voting_id', $round->contest->election->voting_id)
                ->where('is_present', true)
                ->where('can_vote', true)
                ->where('weight', '>=', 1)
                ->get(['device_id', 'weight']);
            $round->eligibleDeviceWeights()->createMany($eligibleAttendees->map(
                fn (VotingAttendee $attendee): array => [
                    'device_id' => $attendee->device_id,
                    'weight_snapshot' => $attendee->weight,
                ],
            )->all());
            $eligibleWeightTotal = (float) $eligibleAttendees->sum('weight');
            $round->update([
                'status' => 'live',
                'opened_at' => now(),
                'closed_at' => null,
                'eligible_weight_total' => $eligibleWeightTotal,
                'quorum_participant_count_snapshot' => $quorumParticipantCount,
            ]);

            return $round->refresh();
        });
    }

    public function close(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            $round = ElectionRound::query()
                ->with(['contest', 'candidates.votes'])
                ->lockForUpdate()
                ->findOrFail($round->id);
            if ($round->status !== 'live') {
                throw new \InvalidArgumentException('Kolo neprebieha.');
            }
            $round->update(['status' => 'closed', 'closed_at' => now()]);
            $results = $this->results($round);
            $electedCandidateIds = collect($results['candidates'])
                ->filter(fn (array $candidate): bool => $candidate['elected'])
                ->pluck('id');

            $round->candidates()
                ->whereIn('id', $electedCandidateIds)
                ->update(['status' => 'elected']);

            $nextCandidates = $this->nextRoundCandidates($round, $results, $electedCandidateIds->all());
            if ($nextCandidates !== []) {
                $this->createFromRoundCandidates($round, $nextCandidates);
            }

            return $round->refresh();
        });
    }

    public function recordVote(ElectionRound $round, ElectionRoundCandidate $candidate, Device $device): ElectionRoundVote
    {
        return DB::transaction(function () use ($round, $candidate, $device): ElectionRoundVote {
            $round = ElectionRound::query()->with('contest.election')->lockForUpdate()->findOrFail($round->id);
            if ($round->status !== 'live') {
                throw new ElectionVoteRejected('round_not_accepting', 'Kolo neprijíma hlasovanie.');
            }

            if ($candidate->election_round_id !== $round->id) {
                throw new ElectionVoteRejected('candidate_not_in_round', 'Kandidát nepatrí do aktívneho kola.');
            }

            $deviceWeight = $round->eligibleDeviceWeights()
                ->where('device_id', $device->id)
                ->first();

            if ($deviceWeight === null) {
                $attendee = VotingAttendee::query()
                    ->where('voting_id', $round->contest->election->voting_id)
                    ->where('device_id', $device->id)
                    ->first();

                if ($attendee !== null && (float) $attendee->weight <= 0) {
                    throw new ElectionVoteRejected('zero_weight', 'Zariadenie má nulovú váhu.');
                }

                throw new ElectionVoteRejected('ineligible_device', 'Zariadenie nie je oprávnené hlasovať v tomto kole.');
            }

            $existingVotes = ElectionRoundVote::query()
                ->where('election_round_id', $round->id)
                ->where('device_id', $device->id)
                ->get();
            if ($round->contest->key === 'chairperson') {
                $firstVote = $existingVotes->first();
                if ($firstVote !== null) {
                    throw new ElectionVoteRejected('duplicate_vote', 'Zariadenie už v tomto kole hlasovalo za kandidáta.');
                }
            } elseif ($existingVotes->contains('election_round_candidate_id', $candidate->id)) {
                throw new ElectionVoteRejected('duplicate_vote', 'Zariadenie už hlasovalo za tohto kandidáta.');
            } elseif ($existingVotes->count() >= $this->remainingSeatCount($round)) {
                throw new ElectionVoteRejected('max_candidates_reached', 'Zariadenie už podporilo maximálny počet kandidátov v tomto kole.');
            }

            return ElectionRoundVote::query()->updateOrCreate(
                ['election_round_candidate_id' => $candidate->id, 'device_id' => $device->id],
                ['election_round_id' => $round->id, 'weight_snapshot' => $deviceWeight->weight_snapshot, 'voted_at' => now()],
            );
        });
    }

    /**
     * @return array{total_weight: float, majority_threshold: float, accepted_device_count: int, candidates: array<int, array{id: int, first_name: string, last_name: string, weighted_total: float, elected: bool}>}
     */
    public function results(ElectionRound $round): array
    {
        $round->loadMissing('candidates.votes');
        $totalWeight = (float) ($round->eligible_weight_total ?? 0);
        $majorityBase = $round->quorum_participant_count_snapshot ?? $totalWeight;
        $threshold = floor($majorityBase / 2) + 1;
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
        $seatCount = $this->remainingSeatCount($round);
        $electedIds = $eligible->filter(fn (array $candidate): bool => $candidate['weighted_total'] >= $threshold)
            ->take($seatCount)
            ->pluck('id')
            ->all();

        return [
            'total_weight' => $totalWeight,
            'majority_threshold' => $threshold,
            'accepted_device_count' => $round->votes()->distinct('device_id')->count('device_id'),
            'candidates' => $eligible->map(fn (array $candidate): array => [...$candidate, 'elected' => in_array($candidate['id'], $electedIds, true)])->all(),
            'remaining_seats' => $this->remainingSeatCount($round),
        ];
    }

    private function remainingSeatCount(ElectionRound $round): int
    {
        $electedBeforeRound = ElectionRoundCandidate::query()
            ->whereHas('round', fn ($query) => $query
                ->where('election_contest_id', $round->election_contest_id)
                ->where('round_number', '<', $round->round_number))
            ->where('status', 'elected')
            ->count();

        return max(0, $round->contest()->value('seat_count') - $electedBeforeRound);
    }

    /**
     * @param  array{candidates: array<int, array{id: int, weighted_total: float, elected: bool}>}  $results
     * @param  array<int, int>  $electedCandidateIds
     * @return array<int, ElectionRoundCandidate>
     */
    private function nextRoundCandidates(ElectionRound $round, array $results, array $electedCandidateIds): array
    {
        $remainingSeats = $round->contest->seat_count - ElectionRoundCandidate::query()
            ->whereHas('round', fn ($query) => $query->where('election_contest_id', $round->election_contest_id))
            ->where('status', 'elected')
            ->count();

        if ($remainingSeats <= 0) {
            return [];
        }

        $candidateSnapshots = $round->candidates->keyBy('id');
        $unsuccessfulCandidates = collect($results['candidates'])
            ->reject(fn (array $candidate): bool => in_array($candidate['id'], $electedCandidateIds, true));

        if ($round->contest->key === 'chairperson') {
            if ($round->round_number > 1) {
                return [];
            }

            $runoffCandidateIds = $unsuccessfulCandidates
                ->filter(fn (array $candidate): bool => $candidate['weighted_total'] > 0)
                ->take(2)
                ->pluck('id')
                ->all();

            if (count($runoffCandidateIds) < 2) {
                return [];
            }

            $round->candidates()
                ->whereNotIn('id', $runoffCandidateIds)
                ->update(['status' => 'eliminated']);

            return collect($runoffCandidateIds)
                ->map(fn (int $candidateId): ElectionRoundCandidate => $candidateSnapshots->get($candidateId))
                ->all();
        }

        if ($unsuccessfulCandidates->count() <= $remainingSeats) {
            return [];
        }

        $candidateToEliminate = $unsuccessfulCandidates
            ->sortBy([
                ['weighted_total', 'asc'],
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->first();

        $round->candidates()
            ->whereKey($candidateToEliminate['id'])
            ->update(['status' => 'eliminated']);

        return $unsuccessfulCandidates
            ->reject(fn (array $candidate): bool => $candidate['id'] === $candidateToEliminate['id'])
            ->map(fn (array $candidate): ElectionRoundCandidate => $candidateSnapshots->get($candidate['id']))
            ->all();
    }

    /**
     * @param  array<int, ElectionRoundCandidate>  $candidates
     */
    private function createFromRoundCandidates(ElectionRound $sourceRound, array $candidates): ElectionRound
    {
        $contest = $sourceRound->contest;
        $round = $contest->rounds()->create([
            'round_number' => ((int) $contest->rounds()->max('round_number')) + 1,
            'response_time_seconds' => $sourceRound->response_time_seconds,
        ]);

        $sourceCandidateIds = $sourceRound->candidates
            ->map(fn (ElectionRoundCandidate $candidate): int => (int) $candidate->election_candidate_id)
            ->filter()
            ->all();
        $newCandidates = $contest->candidates
            ->reject(fn (ElectionCandidate $candidate): bool => in_array($candidate->id, $sourceCandidateIds, true));

        $roundCandidates = collect($candidates)
            ->map(fn (ElectionRoundCandidate $candidate): array => [
                'election_candidate_id' => $candidate->election_candidate_id,
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
            ])
            ->merge($newCandidates->map(fn (ElectionCandidate $candidate): array => [
                'election_candidate_id' => $candidate->id,
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
            ]))
            ->sortBy([
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->values()
            ->map(fn (array $candidate, int $index): array => [...$candidate, 'sort_order' => $index + 1]);

        $round->candidates()->createMany($roundCandidates->all());

        return $round;
    }
}
