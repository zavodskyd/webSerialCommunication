<?php

namespace App\Services;

use App\Exceptions\ElectionVoteRejected;
use App\Models\Device;
use App\Models\ElectionCandidateAdmission;
use App\Models\ElectionCandidateAdmissionVote;
use App\Models\VotingAttendee;
use Illuminate\Support\Facades\DB;

class ElectionCandidateAdmissionManager
{
    /**
     * @return array<int, array{key: string, label: string, color: ?string, vote_count: int, weighted_total: float}>
     */
    public function summarizedResults(ElectionCandidateAdmission $admission): array
    {
        $totals = $admission->votes()
            ->selectRaw('option_key, count(*) as vote_count, coalesce(sum(weight_snapshot), 0) as weighted_total')
            ->groupBy('option_key')
            ->get()
            ->keyBy('option_key');

        return collect([
            'A' => ['label' => 'Za', 'color' => 'emerald'],
            'B' => ['label' => 'Proti', 'color' => 'rose'],
            'C' => ['label' => 'Zdržal sa', 'color' => 'slate'],
        ])->map(static function (array $definition, string $key) use ($totals): array {
            $total = $totals->get($key);

            return [
                'key' => $key,
                'label' => $definition['label'],
                'color' => $definition['color'],
                'vote_count' => (int) ($total?->vote_count ?? 0),
                'weighted_total' => (float) ($total?->weighted_total ?? 0),
            ];
        })->values()->all();
    }

    public function start(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->with(['election', 'deviceGroup.ranges'])->lockForUpdate()->findOrFail($admission->id);
            if (! in_array($admission->status, ['draft', 'open', 'closed'], true)) {
                throw new \InvalidArgumentException('Návrh nie je možné spustiť.');
            }
            $this->snapshotEligibleDeviceWeights($admission);
            $admission->update(['status' => 'live', 'opened_at' => now(), 'closed_at' => null, 'results_visible' => false]);

            return $admission->refresh();
        });
    }

    public function stop(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status !== 'live') {
                throw new \InvalidArgumentException('Návrh práve neprebieha.');
            }
            $admission->update(['status' => 'closed', 'closed_at' => now()]);

            return $admission->refresh();
        });
    }

    public function finish(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status !== 'live') {
                return $admission;
            }
            $admission->update(['status' => 'closed', 'closed_at' => now(), 'results_visible' => true]);

            return $admission->refresh();
        });
    }

    public function restart(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->with(['election', 'deviceGroup.ranges'])->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status === 'live') {
                throw new \InvalidArgumentException('Prebiehajúci návrh najprv zastavte.');
            }
            $admission->votes()->delete();
            $this->snapshotEligibleDeviceWeights($admission);
            $admission->update(['status' => 'live', 'opened_at' => now(), 'closed_at' => null, 'resolved_at' => null, 'results_visible' => false]);

            return $admission->refresh();
        });
    }

    public function showResults(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        if ($admission->status === 'live') {
            throw new \InvalidArgumentException('Návrh najprv zastavte.');
        }
        $admission->update(['results_visible' => true]);

        return $admission->refresh();
    }

    public function recordVote(ElectionCandidateAdmission $admission, Device $device, string $optionKey): ElectionCandidateAdmissionVote
    {
        return DB::transaction(function () use ($admission, $device, $optionKey): ElectionCandidateAdmissionVote {
            $admission = ElectionCandidateAdmission::query()->with(['eligibleDeviceWeights', 'contest', 'deviceGroup.ranges', 'election.voting'])->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status === 'live'
                && $admission->opened_at !== null
                && now()->greaterThanOrEqualTo($admission->opened_at->copy()->addSeconds($admission->response_time_seconds))) {
                $admission->update(['status' => 'closed', 'closed_at' => now(), 'results_visible' => true]);
            }
            if ($admission->status !== 'live' || ! in_array($optionKey, ['A', 'B', 'C'], true)) {
                throw new ElectionVoteRejected('admission_not_accepting', 'Návrh kandidáta neprijíma tento hlas.');
            }
            if ($admission->deviceGroup && ! $this->deviceIsInGroup($device, $admission->deviceGroup->ranges->all())) {
                throw new ElectionVoteRejected('outside_device_group', 'Zariadenie je mimo skupiny návrhu kandidáta.');
            }
            $weightSnapshot = $admission->eligibleDeviceWeights->firstWhere('device_id', $device->id);
            if ($weightSnapshot === null) {
                $attendee = VotingAttendee::query()
                    ->where('voting_id', $admission->election->voting_id)
                    ->where('device_id', $device->id)
                    ->first();

                if ($attendee !== null && (float) $attendee->weight <= 0) {
                    throw new ElectionVoteRejected('zero_weight', 'Zariadenie má nulovú váhu.');
                }

                throw new ElectionVoteRejected('ineligible_device', 'Zariadenie nie je oprávnené hlasovať o tomto návrhu.');
            }

            return ElectionCandidateAdmissionVote::query()->updateOrCreate(['election_candidate_admission_id' => $admission->id, 'device_id' => $device->id], ['option_key' => $optionKey, 'weight_snapshot' => $weightSnapshot->weight_snapshot, 'voted_at' => now()]);
        });
    }

    public function resolve(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->with('contest')->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status !== 'closed' || ! $admission->results_visible) {
                throw new \InvalidArgumentException('Najprv zastavte hlasovanie a zobrazte výsledok.');
            }
            $votes = $admission->votes()->get();
            $yes = $votes->where('option_key', 'A')->sum('weight_snapshot');
            $majorityBase = $admission->quorum_participant_count_snapshot
                ?? $admission->eligible_weight_total;
            $majorityThreshold = floor($majorityBase / 2) + 1;
            $accepted = $yes >= $majorityThreshold;
            $admission->update(['status' => $accepted ? 'accepted' : 'rejected', 'resolved_at' => now()]);
            if ($accepted) {
                $admission->contest->candidates()->firstOrCreate(['first_name' => $admission->first_name, 'last_name' => $admission->last_name], ['status' => 'approved']);
            }

            return $admission->refresh();
        });
    }

    private function deviceIsInGroup(Device $device, array $ranges): bool
    {
        $number = (int) ltrim($device->device_number, '0');

        return collect($ranges)->contains(fn ($range) => $number >= $range->start_number && $number <= $range->end_number);
    }

    private function snapshotEligibleDeviceWeights(ElectionCandidateAdmission $admission): void
    {
        $quorumParticipantCount = $admission->device_group_id === null
            ? $admission->election->quorum_participant_count
            : $admission->deviceGroup?->quorum_participant_count;
        if (($quorumParticipantCount ?? 0) < 1) {
            throw new \InvalidArgumentException($admission->device_group_id === null
                ? 'Pred spustením nastavte celkový počet účastníkov pre základ väčšiny.'
                : 'Pre vybranú lokalitu nastavte počet účastníkov pre kvórum.');
        }

        $eligibleAttendeesQuery = VotingAttendee::query()
            ->where('voting_id', $admission->election->voting_id)
            ->where('is_present', true)
            ->where('can_vote', true)
            ->where('weight', '>=', 1);

        if ($admission->deviceGroup) {
            $eligibleAttendeesQuery->whereHas('device', function ($query) use ($admission): void {
                $query->where(function ($query) use ($admission): void {
                    foreach ($admission->deviceGroup->ranges as $range) {
                        $query->orWhereRaw('CAST(device_number AS INTEGER) between ? and ?', [$range->start_number, $range->end_number]);
                    }
                });
            });
        }

        $eligibleAttendees = $eligibleAttendeesQuery->get(['device_id', 'weight']);

        $admission->eligibleDeviceWeights()->delete();
        $admission->eligibleDeviceWeights()->createMany($eligibleAttendees->map(
            fn (VotingAttendee $attendee): array => [
                'device_id' => $attendee->device_id,
                'weight_snapshot' => $attendee->weight,
            ],
        )->all());
        $admission->update([
            'eligible_weight_total' => (float) $eligibleAttendees->sum('weight'),
            'quorum_participant_count_snapshot' => $quorumParticipantCount,
        ]);
    }
}
