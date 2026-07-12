<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionRound extends Model
{
    protected $fillable = ['election_contest_id', 'round_number', 'status', 'response_time_seconds', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
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
}
