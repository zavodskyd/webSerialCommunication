<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionCandidate extends Model
{
    protected $fillable = [
        'election_contest_id',
        'first_name',
        'last_name',
        'status',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(ElectionContest::class, 'election_contest_id');
    }
}
