<?php

namespace App\Support;

/**
 * @phpstan-type DecodedQomoFrame array{deviceNumber: int, buttonName: string}
 */
class QomoHexFrameDecoder
{
    private const BUTTONS = [
        0x80 => 'A',
        0x90 => 'B',
        0xA0 => 'C',
        0xB0 => 'D',
        0xC0 => 'E',
        0xD0 => 'F',
        0xE0 => 'Ruka',
    ];

    /**
     * @return DecodedQomoFrame|null
     */
    public function decode(string $hex): ?array
    {
        $normalizedHex = strtolower(trim($hex));

        if (preg_match('/\A[0-9a-f]{6}\z/', $normalizedHex) !== 1) {
            return null;
        }

        $byte1 = hexdec(substr($normalizedHex, 0, 2));
        $byte2 = hexdec(substr($normalizedHex, 2, 2));
        $byte3 = hexdec(substr($normalizedHex, 4, 2));

        if (($byte1 ^ $byte2) !== $byte3 || $byte1 < 0x20) {
            return null;
        }

        $buttonName = self::BUTTONS[$byte2 & 0xF0] ?? null;

        if ($buttonName === null) {
            return null;
        }

        return [
            'deviceNumber' => (($byte1 - 0x20) << 4) | ($byte2 & 0x0F),
            'buttonName' => $buttonName,
        ];
    }
}
