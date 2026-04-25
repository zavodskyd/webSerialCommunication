<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VotingAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'voting_id',
        'device_id',
        'weight',
        'is_present',
        'can_vote',
        'registered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'is_present' => 'boolean',
            'can_vote' => 'boolean',
            'registered_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function voting(): BelongsTo
    {
        return $this->belongsTo(Voting::class);
    }
}
