<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class VotingQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'voting_id',
        'order',
        'status',
        'label',
        'text',
        'response_time_seconds',
        'opened_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'response_time_seconds' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function voting(): BelongsTo
    {
        return $this->belongsTo(Voting::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(VotingOption::class)->orderBy('sort_order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * @return array<int, array{key: string, label: string, color: string|null, weighted_total: float, vote_count: int}>
     */
    public function summarizedResults(): array
    {
        $totals = $this->votes()
            ->selectRaw('option_key, COUNT(*) as vote_count, COALESCE(SUM(weight_snapshot), 0) as weighted_total')
            ->groupBy('option_key')
            ->get()
            ->keyBy('option_key');

        return $this->options()
            ->orderBy('sort_order')
            ->get()
            ->map(function (VotingOption $option) use ($totals): array {
                $optionTotals = $totals->get($option->key);

                return [
                    'key' => $option->key,
                    'label' => $option->label,
                    'color' => $option->color,
                    'weighted_total' => (float) ($optionTotals?->weighted_total ?? 0),
                    'vote_count' => (int) ($optionTotals?->vote_count ?? 0),
                ];
            })
            ->all();
    }

    public function recordVote(VotingAttendee $attendee, string $optionKey): Vote
    {
        if ($attendee->voting_id !== $this->voting_id) {
            throw new InvalidArgumentException('Attendee does not belong to this voting.');
        }

        if (! $attendee->is_present || ! $attendee->can_vote || (float) $attendee->weight <= 0) {
            throw new InvalidArgumentException('Attendee cannot vote on this question.');
        }

        if ($this->status === 'closed') {
            throw new InvalidArgumentException('Voting question is already closed.');
        }

        $option = $this->options()
            ->where('key', $optionKey)
            ->first();

        if (! $option) {
            throw new InvalidArgumentException('Invalid voting option.');
        }

        return Vote::query()->updateOrCreate(
            [
                'voting_question_id' => $this->id,
                'device_id' => $attendee->device_id,
            ],
            [
                'voting_option_id' => $option->id,
                'option_key' => $option->key,
                'weight_snapshot' => $attendee->weight,
                'voted_at' => now(),
            ],
        );
    }
}
