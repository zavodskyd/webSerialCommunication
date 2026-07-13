<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionRoundDeviceWeight extends Model
{
    protected $fillable = ['election_round_id', 'device_id', 'weight_snapshot'];

    protected function casts(): array
    {
        return ['weight_snapshot' => 'decimal:2'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(ElectionRound::class, 'election_round_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
