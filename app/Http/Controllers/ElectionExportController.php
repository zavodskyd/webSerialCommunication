<?php

namespace App\Http\Controllers;

use App\Models\Election;
use App\Models\VoteEvent;
use App\Models\Voting;
use App\Services\ElectionRoundManager;
use App\Services\Voting\NativePdfExporter;
use App\Support\PrintAssetResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ElectionExportController extends Controller
{
    public function __construct(
        private readonly ElectionRoundManager $rounds,
        private readonly PrintAssetResolver $printAssetResolver,
    ) {}

    public function results(Voting $voting): View
    {
        return view('election-exports.results', $this->resultsViewData($voting));
    }

    public function resultsPdf(Voting $voting, NativePdfExporter $exporter): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), Response::HTTP_CONFLICT, 'PDF export je dostupný len v NativePHP desktop aplikácii.');

        $html = view('election-exports.results', $this->resultsViewData(
            $voting,
            exportPdfUrl: null,
            showPrintToolbar: false,
            showPrintScript: false,
            inlineAppCss: $this->printAssetResolver->appCss(),
        ))->render();

        return response()->json($exporter->export(
            html: $html,
            filename: 'Vysledky volieb - '.$voting->name.'.pdf',
        )->toArray());
    }

    public function audit(Voting $voting): StreamedResponse
    {
        $this->election($voting);

        return response()->streamDownload(function () use ($voting): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'received_at', 'context', 'contest', 'round', 'candidate_or_proposal',
                'device_number', 'button_name', 'accepted', 'rejection_reason', 'raw_hex',
            ]);

            VoteEvent::query()
                ->where('voting_id', $voting->id)
                ->where(function ($query): void {
                    $query->whereNotNull('election_round_id')
                        ->orWhereNotNull('election_candidate_admission_id');
                })
                ->with([
                    'device:id,device_number',
                    'electionRound.contest:id,name',
                    'electionRoundCandidate:id,first_name,last_name',
                    'electionCandidateAdmission.contest:id,name',
                ])
                ->orderBy('received_at')
                ->orderBy('id')
                ->chunkById(500, function ($events) use ($handle): void {
                    foreach ($events as $event) {
                        $round = $event->electionRound;
                        $admission = $event->electionCandidateAdmission;
                        $candidate = $event->electionRoundCandidate;

                        fputcsv($handle, [
                            $event->received_at?->toIso8601String() ?? '',
                            $round ? 'election_round' : 'candidate_admission',
                            $round?->contest?->name ?? $admission?->contest?->name ?? '',
                            $round?->round_number ?? '',
                            $candidate ? trim($candidate->first_name.' '.$candidate->last_name) : trim(($admission?->first_name ?? '').' '.($admission?->last_name ?? '')),
                            $event->device?->device_number ?? '',
                            $event->button_name ?? '',
                            $event->accepted ? '1' : '0',
                            $event->rejection_reason ?? '',
                            $event->raw_hex,
                        ]);
                    }
                });
            fclose($handle);
        }, 'Audit volieb - '.$voting->name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultsViewData(
        Voting $voting,
        ?string $exportPdfUrl = null,
        bool $showPrintToolbar = true,
        bool $showPrintScript = true,
        ?string $inlineAppCss = null,
    ): array {
        $election = $this->election($voting);
        $election->load(['contests.rounds' => fn ($query) => $query
            ->where('status', 'closed')
            ->with('candidates.votes')]);

        $roundResults = $election->contests->flatMap(fn ($contest) => $contest->rounds->map(
            fn ($round): array => ['round' => $round, 'results' => $this->rounds->results($round)],
        ));

        return [
            'voting' => $voting,
            'roundResults' => $roundResults,
            'exportPdfUrl' => $exportPdfUrl ?? route('elections.exports.results.pdf', $voting),
            'showPrintToolbar' => $showPrintToolbar,
            'showPrintScript' => $showPrintScript,
            'inlineAppCss' => $inlineAppCss,
        ];
    }

    private function election(Voting $voting): Election
    {
        abort_unless($voting->voting_type === 'election', 404);

        return $voting->election()->firstOrFail();
    }
}
