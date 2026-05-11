<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingQuestion;
use App\Services\Voting\NativePdfExporter;
use App\Support\PrintAssetResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VotingExportController extends Controller
{
    public function __construct(private readonly PrintAssetResolver $printAssetResolver) {}

    public function results(Voting $voting): View
    {
        return view('voting-exports.results', $this->resultsViewData($voting));
    }

    public function pressedOptions(Voting $voting): View
    {
        return view('voting-exports.pressed-options', $this->pressedOptionsViewData($voting));
    }

    public function resultsPdf(Voting $voting, NativePdfExporter $exporter): JsonResponse
    {
        return $this->exportPdf(
            exporter: $exporter,
            view: 'voting-exports.results',
            data: $this->resultsViewData($voting, exportPdfUrl: null, showPrintToolbar: false, showPrintScript: false, inlineAppCss: $this->printAssetResolver->appCss()),
            filename: $this->resultsFilename($voting),
        );
    }

    public function pressedOptionsPdf(Voting $voting, NativePdfExporter $exporter): JsonResponse
    {
        return $this->exportPdf(
            exporter: $exporter,
            view: 'voting-exports.pressed-options',
            data: $this->pressedOptionsViewData($voting, exportPdfUrl: null, showPrintToolbar: false, showPrintScript: false, inlineAppCss: $this->printAssetResolver->appCss()),
            filename: $this->pressedOptionsFilename($voting),
        );
    }

    /**
     * @return array{voting: Voting, questions: Collection<int, VotingQuestion>, exportPdfUrl: ?string, showPrintToolbar: bool, showPrintScript: bool, inlineAppCss: ?string}
     */
    private function resultsViewData(
        Voting $voting,
        ?string $exportPdfUrl = null,
        bool $showPrintToolbar = true,
        bool $showPrintScript = true,
        ?string $inlineAppCss = null,
    ): array {
        $voting->load([
            'questions' => fn ($query) => $query
                ->where('status', 'closed')
                ->with(['options', 'votes.device']),
        ]);

        return [
            'voting' => $voting,
            'questions' => $voting->questions,
            'exportPdfUrl' => $exportPdfUrl ?? route('votings.exports.results.pdf', $voting),
            'showPrintToolbar' => $showPrintToolbar,
            'showPrintScript' => $showPrintScript,
            'inlineAppCss' => $inlineAppCss,
        ];
    }

    /**
     * @return array{
     *     voting: Voting,
     *     questions: Collection<int, VotingQuestion>,
     *     questionRows: array<int, array<int, array{device_number: string, weight: float, button_name: string}>>,
     *     exportPdfUrl: ?string,
     *     showPrintToolbar: bool,
     *     showPrintScript: bool,
     *     inlineAppCss: ?string
     * }
     */
    private function pressedOptionsViewData(
        Voting $voting,
        ?string $exportPdfUrl = null,
        bool $showPrintToolbar = true,
        bool $showPrintScript = true,
        ?string $inlineAppCss = null,
    ): array {
        $voting->load([
            'attendees.device',
            'questions' => fn ($query) => $query
                ->where('status', 'closed')
                ->with([
                    'voteEvents' => fn ($voteEventsQuery) => $voteEventsQuery
                        ->whereNotNull('device_id')
                        ->whereNotNull('button_name')
                        ->with('device')
                        ->orderByDesc('received_at')
                        ->orderByDesc('id'),
                ]),
        ]);

        $attendeesByDeviceId = $voting->attendees->keyBy('device_id');
        $questionRows = [];

        foreach ($voting->questions as $question) {
            $latestEvents = $question->voteEvents
                ->unique('device_id')
                ->sortBy(fn ($event) => Device::sortKeyForDeviceNumber($event->device?->device_number))
                ->values();

            $questionRows[$question->id] = $latestEvents
                ->map(function ($event) use ($attendeesByDeviceId): array {
                    $attendee = $attendeesByDeviceId->get($event->device_id);

                    return [
                        'device_number' => $event->device?->device_number ?? 'Neznáme',
                        'weight' => (float) ($attendee?->weight ?? 0),
                        'button_name' => (string) $event->button_name,
                    ];
                })
                ->all();
        }

        return [
            'voting' => $voting,
            'questions' => $voting->questions,
            'questionRows' => $questionRows,
            'exportPdfUrl' => $exportPdfUrl ?? route('votings.exports.pressed-options.pdf', $voting),
            'showPrintToolbar' => $showPrintToolbar,
            'showPrintScript' => $showPrintScript,
            'inlineAppCss' => $inlineAppCss,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function exportPdf(
        NativePdfExporter $exporter,
        string $view,
        array $data,
        string $filename,
    ): JsonResponse {
        Log::info('VotingExportController: native pdf export requested', [
            'view' => $view,
            'filename' => $filename,
            'native_running' => config('nativephp-internal.running'),
        ]);

        if (! config('nativephp-internal.running')) {
            return response()->json([
                'message' => 'PDF export je dostupný len v NativePHP desktop aplikácii.',
            ], Response::HTTP_CONFLICT);
        }

        $html = view($view, $data)->render();

        Log::info('VotingExportController: native pdf export rendered html', [
            'view' => $view,
            'filename' => $filename,
            'html_length' => strlen($html),
        ]);

        $result = $exporter->export(html: $html, filename: $filename);

        return response()->json($result->toArray());
    }

    private function resultsFilename(Voting $voting): string
    {
        return 'Vysledky hlasovania - '.$voting->name.'.pdf';
    }

    private function pressedOptionsFilename(Voting $voting): string
    {
        return 'Stlacene moznosti - '.$voting->name.'.pdf';
    }
}
