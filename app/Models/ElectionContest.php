<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectionContest extends Model
{
    protected $fillable = [
        'election_id',
        'device_group_id',
        'key',
        'name',
        'seat_count',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ElectionCandidate::class)
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(ElectionRound::class)->orderBy('round_number');
    }
}
