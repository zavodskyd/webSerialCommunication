<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionRoundCandidate extends Model
{
    protected $fillable = ['election_round_id', 'election_candidate_id', 'first_name', 'last_name', 'sort_order', 'status'];

    public function round(): BelongsTo
    {
        return $this->belongsTo(ElectionRound::class, 'election_round_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ElectionCandidate::class, 'election_candidate_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ElectionRoundVote::class);
    }
}
