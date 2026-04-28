<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Voting;
use App\Services\Voting\VoteRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SerialFrameController extends Controller
{
    public function __construct(private readonly VoteRecorder $recorder) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hex' => ['required', 'string'],
            'received_at' => ['nullable', 'string'],
        ]);

        $voting = Voting::query()
            ->whereNotNull('current_voting_question_id')
            ->latest()
            ->first();

        if ($voting === null) {
            return response()->json([
                'accepted' => false,
                'message' => 'No active voting',
            ]);
        }

        $question = $voting->questions()->find($voting->current_voting_question_id);

        if ($question === null) {
            return response()->json([
                'accepted' => false,
                'message' => 'No active voting question',
            ]);
        }

        $result = $this->recorder->record(
            code: $validated['hex'],
            voting: $voting,
            question: $question,
            collectorEnabledHint: false,
            source: 'node-helper',
        );

        return response()->json($result->toArray());
    }
}
