<?php

namespace App\Http\Controllers;

use App\Models\Voting;
use App\Services\Backup\NativeBackupExporter;
use App\Services\Backup\NativeBackupExportResult;
use App\Services\Voting\VotingConfigurationTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VotingConfigurationController extends Controller
{
    public function __construct(
        private readonly VotingConfigurationTransfer $configurationTransfer,
        private readonly NativeBackupExporter $nativeBackupExporter,
    ) {}

    public function exportVoting(Voting $voting): StreamedResponse|JsonResponse
    {
        return $this->exportConfiguration($voting, 'voting');
    }

    public function exportElection(Voting $voting): StreamedResponse|JsonResponse
    {
        return $this->exportConfiguration($voting, 'election');
    }

    public function importNewVoting(Request $request): RedirectResponse
    {
        return $this->importConfiguration($request, 'voting');
    }

    public function importIntoVoting(Request $request, Voting $voting): RedirectResponse
    {
        return $this->importConfiguration($request, 'voting', $voting);
    }

    public function importNewElection(Request $request): RedirectResponse
    {
        return $this->importConfiguration($request, 'election');
    }

    public function importIntoElection(Request $request, Voting $voting): RedirectResponse
    {
        return $this->importConfiguration($request, 'election', $voting);
    }

    private function exportConfiguration(Voting $voting, string $type): StreamedResponse|JsonResponse
    {
        $expectedVotingType = $type === 'election' ? 'election' : 'standard';
        abort_unless($voting->voting_type === $expectedVotingType, 404);

        $payload = $this->configurationTransfer->export($voting);
        $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $filename = Str::slug($voting->name).'-konfiguracia.json';

        if (config('nativephp-internal.running')) {
            return $this->nativeExportResponse(
                $this->nativeBackupExporter->exportContents(
                    contents: $contents,
                    filename: $filename,
                    dialogTitle: $type === 'election' ? 'Uložiť konfiguráciu volieb' : 'Uložiť konfiguráciu hlasovania',
                    filterName: 'JSON konfigurácia',
                    extensions: ['json'],
                )
            );
        }

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    private function importConfiguration(
        Request $request,
        string $type,
        ?Voting $target = null,
    ): RedirectResponse {
        $validated = $request->validate([
            'configuration_file' => ['required', 'file', 'max:6144'],
        ]);

        $path = $validated['configuration_file']->getRealPath();
        if ($path === false) {
            return back()->withErrors([
                'configuration_file' => 'Nahraný konfiguračný súbor sa nepodarilo prečítať.',
            ]);
        }

        try {
            $payload = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new InvalidArgumentException('Konfiguračný súbor má neplatnú štruktúru.');
            }

            $this->configurationTransfer->import($payload, $type, $target);
        } catch (InvalidArgumentException|JsonException $exception) {
            return back()->withErrors([
                'configuration_file' => $exception->getMessage(),
            ]);
        }

        $indexRoute = $type === 'election' ? 'elections.index' : 'votings.index';
        $message = $target === null
            ? 'Konfigurácia bola importovaná ako nový záznam.'
            : 'Konfigurácia bola importovaná. Pôvodný názov zostal zachovaný.';

        return redirect()->route($indexRoute)->with('status', $message);
    }

    private function nativeExportResponse(NativeBackupExportResult $result): JsonResponse
    {
        return response()->json($result->toArray());
    }
}
