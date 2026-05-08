<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_number',
        'code_a',
        'code_b',
        'code_c',
        'code_d',
        'code_e',
        'code_f',
        'code_ruka',
    ];

    public function scopeOrdered(Builder $query): void
    {
        $query
            ->orderByRaw('LENGTH(device_number)')
            ->orderBy('device_number');
    }

    public static function sortKeyForDeviceNumber(?string $deviceNumber): string
    {
        $normalizedDeviceNumber = trim((string) $deviceNumber);

        return str_pad((string) strlen($normalizedDeviceNumber), 10, '0', STR_PAD_LEFT)
            .':'.$normalizedDeviceNumber;
    }

    /**
     * @return array<string, string>
     */
    public function codeMap(): array
    {
        return [
            'A' => $this->code_a,
            'B' => $this->code_b,
            'C' => $this->code_c,
            'D' => $this->code_d,
            'E' => $this->code_e,
            'F' => $this->code_f,
            'Ruka' => $this->code_ruka,
        ];
    }

    public function resolveButtonName(string $code): ?string
    {
        foreach ($this->codeMap() as $buttonName => $deviceCode) {
            if ($deviceCode === $code) {
                return $buttonName;
            }
        }

        return null;
    }
}
