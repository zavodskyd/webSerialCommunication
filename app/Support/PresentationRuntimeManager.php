<?php

namespace App\Support;

use App\Models\PresentationRuntime;
use App\Models\Voting;
use Illuminate\Support\Facades\DB;

class PresentationRuntimeManager
{
    public const PrimaryRuntimeKey = 'primary';

    /**
     * @param  array<string, mixed>  $context
     */
    public function activate(Voting $voting, string $contentType, array $context = []): PresentationRuntime
    {
        return DB::transaction(function () use ($voting, $contentType, $context): PresentationRuntime {
            $runtime = $this->current();

            $runtime->update([
                'voting_id' => $voting->id,
                'content_type' => $contentType,
                'context' => $context,
            ]);

            return $runtime->refresh();
        });
    }

    public function clear(): PresentationRuntime
    {
        $runtime = $this->current();

        $runtime->update([
            'voting_id' => null,
            'content_type' => 'none',
            'context' => [],
        ]);

        return $runtime->refresh();
    }

    public function current(): PresentationRuntime
    {
        return PresentationRuntime::query()->firstOrCreate(
            ['runtime_key' => self::PrimaryRuntimeKey],
            [
                'content_type' => 'none',
                'context' => [],
            ],
        );
    }
}
