<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Election extends Model
{
    protected $fillable = [
        'voting_id',
        'status',
        'active_device_limit',
        'candidate_admissions_locked',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_admissions_locked' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function voting(): BelongsTo
    {
        return $this->belongsTo(Voting::class);
    }

    public function deviceGroups(): HasMany
    {
        return $this->hasMany(DeviceGroup::class)->orderBy('sort_order');
    }

    public function contests(): HasMany
    {
        return $this->hasMany(ElectionContest::class)->orderBy('sort_order');
    }

    public function createDefaultContests(): void
    {
        $this->contests()->createMany(self::defaultContestAttributes());
    }

    /**
     * @return list<array{key: string, name: string, seat_count: int, sort_order: int}>
     */
    public static function defaultContestAttributes(): array
    {
        return [
            ['key' => 'chairperson', 'name' => 'Predseda', 'seat_count' => 1, 'sort_order' => 1],
            ['key' => 'board-hliny', 'name' => 'Predstavenstvo Hliny', 'seat_count' => 2, 'sort_order' => 2],
            ['key' => 'board-solinky', 'name' => 'Predstavenstvo Solinky', 'seat_count' => 3, 'sort_order' => 3],
            ['key' => 'board-vlcince', 'name' => 'Predstavenstvo Vlčince', 'seat_count' => 3, 'sort_order' => 4],
            ['key' => 'board-rozptyl-stare-mesto', 'name' => 'Predstavenstvo Rozptyl/Staré Mesto', 'seat_count' => 2, 'sort_order' => 5],
            ['key' => 'supervisory-committee', 'name' => 'Kontrolná komisia', 'seat_count' => 7, 'sort_order' => 6],
        ];
    }
}
