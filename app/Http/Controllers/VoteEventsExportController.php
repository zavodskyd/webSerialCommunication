<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voting;
use App\Models\VotingQuestion;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoteEventsExportController extends Controller
{
    public function __invoke(Voting $voting, VotingQuestion $question): StreamedResponse
    {
        abort_unless($question->voting_id === $voting->id, 404);

        $filename = sprintf('voting-%d-question-%d-events.csv', $voting->id, $question->order);

        $callback = function () use ($question): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'received_at',
                'source',
                'device_number',
                'button_name',
                'raw_hex',
                'accepted',
                'rejection_reason',
            ]);

            $question->voteEvents()
                ->with('device:id,device_number')
                ->orderBy('received_at')
                ->orderBy('id')
                ->chunk(500, function ($events) use ($handle): void {
                    foreach ($events as $event) {
                        fputcsv($handle, [
                            optional($event->received_at)->toIso8601String() ?? '',
                            (string) $event->source,
                            $event->device?->device_number ?? '',
                            (string) ($event->button_name ?? ''),
                            (string) $event->raw_hex,
                            $event->accepted ? '1' : '0',
                            (string) ($event->rejection_reason ?? ''),
                        ]);
                    }
                });

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
