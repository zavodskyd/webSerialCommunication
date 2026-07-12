<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionRoundVote extends Model
{
    protected $fillable = ['election_round_id', 'election_round_candidate_id', 'device_id', 'weight_snapshot', 'voted_at'];

    protected function casts(): array
    {
        return ['weight_snapshot' => 'decimal:2', 'voted_at' => 'datetime'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ElectionRound::class, 'election_round_id');
    }

    public function roundCandidate(): BelongsTo
    {
        return $this->belongsTo(ElectionRoundCandidate::class, 'election_round_candidate_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
