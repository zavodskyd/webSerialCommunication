<?php

declare(strict_types=1);

namespace App\Services\Voting;

final readonly class NativePdfExportResult
{
    public function __construct(
        public bool $cancelled,
        public ?string $path = null,
    ) {}

    /**
     * @return array{cancelled: bool, path: ?string}
     */
    public function toArray(): array
    {
        return [
            'cancelled' => $this->cancelled,
            'path' => $this->path,
        ];
    }
}
