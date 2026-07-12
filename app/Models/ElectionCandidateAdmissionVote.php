<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectionCandidateAdmissionVote extends Model
{
    protected $fillable = ['election_candidate_admission_id', 'device_id', 'option_key', 'weight_snapshot', 'voted_at'];

    protected function casts(): array
    {
        return ['weight_snapshot' => 'decimal:2', 'voted_at' => 'datetime'];
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
