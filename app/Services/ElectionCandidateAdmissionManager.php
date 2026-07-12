<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ElectionCandidateAdmission;
use App\Models\ElectionCandidateAdmissionVote;
use App\Models\VotingAttendee;
use Illuminate\Support\Facades\DB;

class ElectionCandidateAdmissionManager
{
    public function open(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        if ($admission->contest->key === 'chairperson') {
            throw new \InvalidArgumentException('Chairperson admissions are not allowed.');
        }
        $admission->update(['status' => 'open', 'opened_at' => now(), 'resolved_at' => null]);

        return $admission->refresh();
    }

    public function recordVote(ElectionCandidateAdmission $admission, Device $device, string $optionKey): ElectionCandidateAdmissionVote
    {
        return DB::transaction(function () use ($admission, $device, $optionKey): ElectionCandidateAdmissionVote {
            $admission = ElectionCandidateAdmission::query()->with(['contest', 'deviceGroup.ranges', 'election.voting'])->lockForUpdate()->findOrFail($admission->id);
            if ($admission->status !== 'open' || ! in_array($optionKey, ['A', 'B', 'C'], true)) {
                throw new \InvalidArgumentException('Admission is not accepting this vote.');
            }
            if ($admission->deviceGroup && ! $this->deviceIsInGroup($device, $admission->deviceGroup->ranges->all())) {
                throw new \InvalidArgumentException('Device is outside the admission group.');
            }
            $attendee = VotingAttendee::query()->where('voting_id', $admission->election->voting_id)->where('device_id', $device->id)->firstOrFail();
            if (! $attendee->can_vote || ! $attendee->is_present || (float) $attendee->weight <= 0) {
                throw new \InvalidArgumentException('Device has no voting weight.');
            }

            return ElectionCandidateAdmissionVote::query()->updateOrCreate(['election_candidate_admission_id' => $admission->id, 'device_id' => $device->id], ['option_key' => $optionKey, 'weight_snapshot' => $attendee->weight, 'voted_at' => now()]);
        });
    }

    public function resolve(ElectionCandidateAdmission $admission): ElectionCandidateAdmission
    {
        return DB::transaction(function () use ($admission): ElectionCandidateAdmission {
            $admission = ElectionCandidateAdmission::query()->with('contest')->lockForUpdate()->findOrFail($admission->id);
            $votes = $admission->votes()->get();
            $total = $votes->sum('weight_snapshot');
            $yes = $votes->where('option_key', 'A')->sum('weight_snapshot');
            $accepted = $yes > ($total / 2);
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
}
