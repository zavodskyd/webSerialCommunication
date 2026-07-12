<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceGroupRange extends Model
{
    protected $fillable = [
        'device_group_id',
        'start_number',
        'end_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_number' => 'integer',
            'end_number' => 'integer',
        ];
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }
}
