<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoteEvent extends Model
{
    protected $fillable = [
        'voting_id',
        'voting_question_id',
        'election_candidate_admission_id',
        'election_round_id',
        'election_round_candidate_id',
        'device_id',
        'raw_hex',
        'source',
        'button_name',
        'accepted',
        'rejection_reason',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function voting(): BelongsTo
    {
        return $this->belongsTo(Voting::class);
    }

    public function votingQuestion(): BelongsTo
    {
        return $this->belongsTo(VotingQuestion::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
