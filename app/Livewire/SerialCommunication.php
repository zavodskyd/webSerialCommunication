<?php

namespace App\Livewire;

use App\Models\Device;
use App\Support\QomoHexFrameDecoder;
use App\Support\SerialAgentClient;
use App\Support\SerialAgentTestMonitor;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SerialCommunication extends Component
{
    public string $result = '';

    public array $deviceRows = [];

    public array $visibleDeviceRows = [];

    public array $deviceButtonCounts = [];

    public array $recentFrames = [];

    public ?string $activeCode = null;

    public ?string $lastMatchedDeviceNumber = null;

    public ?string $lastButtonName = null;

    public bool $serialConnected = false;

    public bool $collecting = false;

    public ?string $connectedPortPath = null;

    public int $queuedFrames = 0;

    public int $totalFrames = 0;

    public int $decodedFrames = 0;

    public int $invalidFrames = 0;

    public ?bool $agentHealthy = null;

    public ?string $agentError = null;

    public ?string $statusMessage = null;

    public function mount(SerialAgentClient $client, SerialAgentTestMonitor $monitor): void
    {
        $this->deviceRows = Device::query()
            ->ordered()
            ->pluck('device_number')
            ->map(fn (string $deviceNumber): array => [
                'displayNumber' => $deviceNumber,
                'normalizedNumber' => $this->normalizeDeviceNumber($deviceNumber),
            ])
            ->all();

        $this->refreshState($client, $monitor);
    }

    public function render(): View
    {
        return view('livewire.serial-communication');
    }

    public function refreshState(SerialAgentClient $client, SerialAgentTestMonitor $monitor): void
    {
        $response = $client->health();

        $this->hydrateAgentState($response);
        $this->syncMonitor($monitor);
    }

    public function startCollection(SerialAgentClient $client, SerialAgentTestMonitor $monitor): void
    {
        $monitor->reset();
        $response = $client->command('start');

        $this->hydrateAgentState($response);
        $this->syncMonitor($monitor);
        $this->statusMessage = ($response['ok'] ?? false) === true
            ? 'Zber bol spustený.'
            : 'Nepodarilo sa spustiť zber: '.($response['error'] ?? 'unknown');
    }

    public function stopCollection(SerialAgentClient $client, SerialAgentTestMonitor $monitor): void
    {
        $response = $client->command('stop');

        $this->hydrateAgentState($response);
        $this->syncMonitor($monitor);
        $this->statusMessage = ($response['ok'] ?? false) === true
            ? 'Zber bol zastavený.'
            : 'Nepodarilo sa zastaviť zber: '.($response['error'] ?? 'unknown');
    }

    public function disconnectAgent(SerialAgentClient $client, SerialAgentTestMonitor $monitor): void
    {
        $response = $client->command('close');

        $this->hydrateAgentState($response);
        $this->syncMonitor($monitor);
        $this->statusMessage = ($response['ok'] ?? false) === true
            ? 'Serial Agent spojenie bolo ukončené.'
            : 'Nepodarilo sa odpojiť Serial Agent: '.($response['error'] ?? 'unknown');
    }

    public function clearReceivedData(SerialAgentTestMonitor $monitor): void
    {
        $monitor->reset();
        $this->syncMonitor($monitor);
        $this->statusMessage = 'Prijaté dáta boli vymazané.';
    }

    /**
     * @return array{found: bool, deviceNumber: string|null, buttonName: string|null, code: string, message: string}
     */
    public function checkCode(string $code): array
    {
        $decoder = app(QomoHexFrameDecoder::class);
        $normalizedCode = strtolower(trim($code));
        $decodedFrame = $decoder->decode($normalizedCode);

        if ($decodedFrame === null) {
            $this->result = 'Frame '.$normalizedCode.' sa nepodarilo dekódovať.';

            return [
                'found' => false,
                'deviceNumber' => null,
                'buttonName' => null,
                'code' => $normalizedCode,
                'message' => $this->result,
            ];
        }

        $this->updateActiveCode($normalizedCode);
        $this->lastMatchedDeviceNumber = (string) $decodedFrame['deviceNumber'];
        $this->lastButtonName = $decodedFrame['buttonName'];
        $this->result = 'Číslo zariadenia: '.$this->lastMatchedDeviceNumber.', Stlačené tlačidlo: '.$this->lastButtonName.' ('.$normalizedCode.')';

        return [
            'found' => true,
            'deviceNumber' => $this->lastMatchedDeviceNumber,
            'buttonName' => $this->lastButtonName,
            'code' => $normalizedCode,
            'message' => $this->result,
        ];
    }

    public function updateActiveCode(string $code): void
    {
        $this->activeCode = $code;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function hydrateAgentState(array $response): void
    {
        $this->agentHealthy = (bool) ($response['ok'] ?? false);
        $this->agentError = $this->agentHealthy ? null : (string) ($response['error'] ?? 'agent not reachable');
        $this->serialConnected = (bool) ($response['connected'] ?? false);
        $this->collecting = (bool) ($response['collecting'] ?? false);
        $this->connectedPortPath = is_string($response['selected_port'] ?? null)
            ? $response['selected_port']
            : null;
        $this->queuedFrames = (int) ($response['queuedFrames'] ?? $response['queued_frames'] ?? 0);
    }

    private function syncMonitor(SerialAgentTestMonitor $monitor): void
    {
        $snapshot = $monitor->snapshot();

        $this->activeCode = $snapshot['lastHex'];
        $this->lastMatchedDeviceNumber = $snapshot['lastDeviceNumber'];
        $this->lastButtonName = $snapshot['lastButtonName'];
        $this->totalFrames = $snapshot['totalFrames'];
        $this->decodedFrames = $snapshot['decodedFrames'];
        $this->invalidFrames = $snapshot['invalidFrames'];
        $this->deviceButtonCounts = $snapshot['deviceButtonCounts'];
        $this->recentFrames = $snapshot['recentFrames'];
        $this->visibleDeviceRows = $this->buildVisibleDeviceRows();
    }

    /**
     * @return array<int, array{displayNumber: string, normalizedNumber: string}>
     */
    private function buildVisibleDeviceRows(): array
    {
        $knownRows = collect($this->deviceRows)->keyBy('normalizedNumber');
        $rows = collect(array_keys($this->deviceButtonCounts))
            ->map(function (string $deviceNumber) use ($knownRows): array {
                $row = $knownRows->get($deviceNumber);

                return is_array($row)
                    ? $row
                    : [
                        'displayNumber' => $deviceNumber,
                        'normalizedNumber' => $deviceNumber,
                    ];
            })
            ->sortBy(fn (array $row): int => (int) $row['normalizedNumber'])
            ->values()
            ->all();

        return $rows;
    }

    private function normalizeDeviceNumber(string $deviceNumber): string
    {
        $normalizedNumber = ltrim($deviceNumber, '0');

        return (string) ($normalizedNumber === '' ? 0 : (int) $normalizedNumber);
    }
}
