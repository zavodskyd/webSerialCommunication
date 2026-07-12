<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PresentationRuntime extends Model
{
    protected $fillable = [
        'runtime_key',
        'voting_id',
        'content_type',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function voting(): BelongsTo
    {
        return $this->belongsTo(Voting::class);
    }
}
