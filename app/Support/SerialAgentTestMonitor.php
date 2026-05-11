<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class SerialAgentTestMonitor
{
    private const CACHE_KEY = 'serial-agent.test-monitor';

    private const MAX_RECENT_FRAMES = 25;

    public function __construct(private readonly QomoHexFrameDecoder $decoder) {}

    public function reset(): void
    {
        $this->putState($this->defaultState());
    }

    /**
     * @return array{
     *     lastHex: ?string,
     *     lastDeviceNumber: ?string,
     *     lastButtonName: ?string,
     *     totalFrames: int,
     *     decodedFrames: int,
     *     invalidFrames: int,
     *     deviceButtonCounts: array<string, array<string, int>>,
     *     recentFrames: array<int, array{hex: string, deviceNumber: ?string, buttonName: ?string, valid: bool, receivedAt: string}>,
     *     updatedAt: ?string
     * }
     */
    public function snapshot(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : $this->defaultState();
    }

    public function recordFrame(string $hex): void
    {
        $state = $this->snapshot();
        $normalizedHex = strtolower(trim($hex));
        $decodedFrame = $this->decoder->decode($normalizedHex);

        $state['lastHex'] = $normalizedHex !== '' ? $normalizedHex : null;
        $state['totalFrames']++;

        if ($decodedFrame === null) {
            $state['invalidFrames']++;
        } else {
            $deviceNumber = (string) $decodedFrame['deviceNumber'];
            $buttonName = $decodedFrame['buttonName'];

            $state['decodedFrames']++;
            $state['lastDeviceNumber'] = $deviceNumber;
            $state['lastButtonName'] = $buttonName;

            $buttonCounts = $state['deviceButtonCounts'][$deviceNumber] ?? $this->emptyButtonCounts();
            $buttonCounts[$buttonName] = ($buttonCounts[$buttonName] ?? 0) + 1;
            $state['deviceButtonCounts'][$deviceNumber] = $buttonCounts;
        }

        array_unshift($state['recentFrames'], [
            'hex' => $normalizedHex,
            'deviceNumber' => $decodedFrame !== null ? (string) $decodedFrame['deviceNumber'] : null,
            'buttonName' => $decodedFrame['buttonName'] ?? null,
            'valid' => $decodedFrame !== null,
            'receivedAt' => now()->toIso8601String(),
        ]);

        $state['recentFrames'] = array_slice($state['recentFrames'], 0, self::MAX_RECENT_FRAMES);

        $this->putState($state);
    }

    /**
     * @return array{
     *     lastHex: ?string,
     *     lastDeviceNumber: ?string,
     *     lastButtonName: ?string,
     *     totalFrames: int,
     *     decodedFrames: int,
     *     invalidFrames: int,
     *     deviceButtonCounts: array<string, array<string, int>>,
     *     recentFrames: array<int, array{hex: string, deviceNumber: ?string, buttonName: ?string, valid: bool, receivedAt: string}>,
     *     updatedAt: ?string
     * }
     */
    private function defaultState(): array
    {
        return [
            'lastHex' => null,
            'lastDeviceNumber' => null,
            'lastButtonName' => null,
            'totalFrames' => 0,
            'decodedFrames' => 0,
            'invalidFrames' => 0,
            'deviceButtonCounts' => [],
            'recentFrames' => [],
            'updatedAt' => null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyButtonCounts(): array
    {
        return [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'E' => 0,
            'F' => 0,
            'Ruka' => 0,
        ];
    }

    /**
     * @param  array{
     *     lastHex: ?string,
     *     lastDeviceNumber: ?string,
     *     lastButtonName: ?string,
     *     totalFrames: int,
     *     decodedFrames: int,
     *     invalidFrames: int,
     *     deviceButtonCounts: array<string, array<string, int>>,
     *     recentFrames: array<int, array{hex: string, deviceNumber: ?string, buttonName: ?string, valid: bool, receivedAt: string}>,
     *     updatedAt?: ?string
     * }  $state
     */
    private function putState(array $state): void
    {
        $state['updatedAt'] = now()->toIso8601String();

        Cache::put(self::CACHE_KEY, $state, now()->addHours(4));
    }
}
