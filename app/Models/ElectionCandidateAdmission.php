<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionCandidateAdmission extends Model
{
    protected $fillable = ['election_id', 'election_contest_id', 'created_election_candidate_id', 'device_group_id', 'first_name', 'last_name', 'status', 'response_time_seconds', 'active_device_limit', 'eligible_weight_total', 'quorum_participant_count_snapshot', 'opened_at', 'closed_at', 'resolved_at', 'results_visible'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'resolved_at' => 'datetime', 'eligible_weight_total' => 'float', 'quorum_participant_count_snapshot' => 'integer', 'results_visible' => 'boolean'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }

    public function createdCandidate(): BelongsTo
    {
        return $this->belongsTo(ElectionCandidate::class, 'created_election_candidate_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ElectionCandidateAdmissionVote::class);
    }

    public function eligibleDeviceWeights(): HasMany
    {
        return $this->hasMany(ElectionCandidateAdmissionDeviceWeight::class);
    }
}
