<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    use HasFactory;

    protected $fillable = [
        'voting_question_id',
        'device_id',
        'voting_option_id',
        'option_key',
        'weight_snapshot',
        'voted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight_snapshot' => 'decimal:2',
            'voted_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(VotingOption::class, 'voting_option_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(VotingQuestion::class, 'voting_question_id');
    }
}
