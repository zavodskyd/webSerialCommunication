<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Voting extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'voting_type',
        'status',
        'question_label',
        'title',
        'header_text',
        'logo_path',
        'default_response_time_seconds',
        'auto_show_results',
        'current_voting_question_id',
        'runtime_remaining_seconds',
        'runtime_timer_running',
        'runtime_collector_enabled',
        'runtime_results_visible',
        'started_at',
        'finished_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_response_time_seconds' => 'integer',
            'auto_show_results' => 'boolean',
            'runtime_remaining_seconds' => 'integer',
            'runtime_timer_running' => 'boolean',
            'runtime_collector_enabled' => 'boolean',
            'runtime_results_visible' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(VotingQuestion::class, 'current_voting_question_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(VotingAttendee::class);
    }

    public function election(): HasOne
    {
        return $this->hasOne(Election::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(VotingQuestion::class)->orderBy('order');
    }

    public function generateQuestions(
        string $baseLabel,
        int $count,
        int $responseTimeSeconds,
    ): void {
        $nextOrder = ((int) $this->questions()->max('order')) + 1;

        for ($offset = 0; $offset < $count; $offset++) {
            $order = $nextOrder + $offset;

            $question = $this->questions()->create([
                'order' => $order,
                'label' => trim($baseLabel).' '.$order,
                'text' => trim($baseLabel).' '.$order,
                'response_time_seconds' => $responseTimeSeconds,
            ]);

            $this->createDefaultOptionsForQuestion($question);
        }
    }

    public function createQuestionWithDefaults(
        int $order,
        string $label,
        string $text,
        int $responseTimeSeconds,
    ): VotingQuestion {
        $question = $this->questions()->create([
            'order' => $order,
            'label' => $label,
            'text' => $text,
            'response_time_seconds' => $responseTimeSeconds,
        ]);

        $this->createDefaultOptionsForQuestion($question);

        return $question;
    }

    private function createDefaultOptionsForQuestion(VotingQuestion $question): void
    {
        $question->options()->createMany([
            [
                'key' => 'A',
                'label' => 'ZA',
                'color' => '#16a34a',
                'sort_order' => 1,
            ],
            [
                'key' => 'B',
                'label' => 'PROTI',
                'color' => '#dc2626',
                'sort_order' => 2,
            ],
            [
                'key' => 'C',
                'label' => 'ZDRŽAL SA',
                'color' => '#2563eb',
                'sort_order' => 3,
            ],
        ]);
    }
}
