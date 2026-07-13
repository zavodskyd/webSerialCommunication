<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionCandidateAdmissionDeviceWeight extends Model
{
    protected $fillable = ['election_candidate_admission_id', 'device_id', 'weight_snapshot'];

    protected function casts(): array
    {
        return ['weight_snapshot' => 'float'];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(ElectionCandidateAdmission::class, 'election_candidate_admission_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
