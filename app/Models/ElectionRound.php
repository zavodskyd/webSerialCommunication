<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionRound extends Model
{
    protected $fillable = ['election_contest_id', 'round_number', 'status', 'response_time_seconds', 'active_device_limit', 'eligible_weight_total', 'quorum_participant_count_snapshot', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'eligible_weight_total' => 'decimal:2', 'quorum_participant_count_snapshot' => 'integer'];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ElectionRoundCandidate::class)->orderBy('sort_order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ElectionRoundVote::class);
    }

    public function eligibleDeviceWeights(): HasMany
    {
        return $this->hasMany(ElectionRoundDeviceWeight::class);
    }
}
