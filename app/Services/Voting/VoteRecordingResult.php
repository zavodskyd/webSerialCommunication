<?php

declare(strict_types=1);

namespace App\Services\Voting;

final readonly class VoteRecordingResult
{
    /**
     * @param  array<int, array{key: string, label: string, color: ?string, vote_count: int, weighted_total: float}>  $results
     */
    public function __construct(
        public bool $accepted,
        public string $message,
        public ?string $deviceNumber,
        public ?string $buttonName,
        public array $results,
        public ?string $rejectionReason = null,
    ) {}

    /**
     * Shape returned by both Livewire's recordVoteFromCode and the internal
     * SerialFrameController endpoint. Stable contract — JS callers depend on it.
     *
     * @return array{accepted: bool, message: string, lastMatchedDeviceNumber: ?string, lastButtonName: ?string, results: array<int, array{key: string, label: string, color: ?string, vote_count: int, weighted_total: float}>}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'message' => $this->message,
            'lastMatchedDeviceNumber' => $this->deviceNumber,
            'lastButtonName' => $this->buttonName,
            'results' => $this->results,
        ];
    }
}
