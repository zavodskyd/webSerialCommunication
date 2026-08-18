<?php

declare(strict_types=1);

namespace App\Services\Voting;

use App\Models\Device;
use App\Models\Voting;
use App\Models\VotingAttendee;

class VotingDeviceRoster
{
    public const DEVICE_COUNT = 340;

    public function ensure(Voting $voting): void
    {
        $existingDeviceNumbers = Device::query()
            ->get(['device_number'])
            ->mapWithKeys(fn (Device $device): array => [(int) $device->device_number => true]);

        $timestamp = now();
        $missingDevices = collect(range(1, self::DEVICE_COUNT))
            ->reject(fn (int $deviceNumber): bool => isset($existingDeviceNumbers[$deviceNumber]))
            ->map(fn (int $deviceNumber): array => [
                'device_number' => str_pad((string) $deviceNumber, 3, '0', STR_PAD_LEFT),
                'code_a' => '',
                'code_b' => '',
                'code_c' => '',
                'code_d' => '',
                'code_e' => '',
                'code_f' => '',
                'code_ruka' => '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($missingDevices !== []) {
            Device::query()->insertOrIgnore($missingDevices);
        }

        $devices = Device::query()
            ->whereRaw('CAST(device_number AS INTEGER) between 1 and ?', [self::DEVICE_COUNT])
            ->get(['id', 'device_number'])
            ->unique(fn (Device $device): int => (int) $device->device_number);

        $attendees = $devices->map(fn (Device $device): array => [
            'voting_id' => $voting->id,
            'device_id' => $device->id,
            'weight' => 0,
            'is_present' => true,
            'can_vote' => true,
            'registered_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all();

        VotingAttendee::query()->insertOrIgnore($attendees);
    }
}
