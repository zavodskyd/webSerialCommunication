<?php

namespace App\Livewire;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

class SerialCommunication extends Component
{
    public string $result = '';

    public EloquentCollection $devices;

    public ?string $activeCode = null;

    public function mount(): void
    {
        $this->devices = Device::query()
            ->ordered()
            ->get();
    }

    public function render(): View
    {
        return view('livewire.serial-communication', [
            'codeLookup' => $this->getCodeLookup(),
            'codePrefixes' => $this->getCodePrefixes(),
        ]);
    }

    public function updateActiveCode(string $code): void
    {
        $this->activeCode = $code;
    }

    /**
     * @return array{found: bool, deviceNumber: string|null, buttonName: string|null, code: string, message: string}
     */
    public function checkCode(string $code): array
    {
        $device = $this->devices->first(function (Device $device) use ($code): bool {
            return in_array($code, [
                $device->code_a,
                $device->code_b,
                $device->code_c,
                $device->code_d,
                $device->code_e,
                $device->code_f,
                $device->code_ruka,
            ], true);
        });

        if ($device) {
            $this->updateActiveCode($code);
            $buttonName = $this->resolveButtonName($device, $code);

            $this->result = 'Názov zariadenia: '.$device->device_number.', Stlačená hodnota: '.$buttonName.' ('.$code.')';

            return [
                'found' => true,
                'deviceNumber' => $device->device_number,
                'buttonName' => $buttonName,
                'code' => $code,
                'message' => $this->result,
            ];
        } else {
            $this->result = 'Kód '.$code.' sa nenašiel';

            return [
                'found' => false,
                'deviceNumber' => null,
                'buttonName' => null,
                'code' => $code,
                'message' => $this->result,
            ];
        }
    }

    /**
     * @return array<string, array{deviceNumber: string, buttonName: string}>
     */
    public function getCodeLookup(): array
    {
        $lookup = [];

        foreach ($this->devices as $device) {
            foreach ($this->deviceCodes($device) as $buttonName => $deviceCode) {
                if ($deviceCode === '') {
                    continue;
                }

                $lookup[$deviceCode] = [
                    'deviceNumber' => $device->device_number,
                    'buttonName' => $buttonName,
                ];
            }
        }

        return $lookup;
    }

    /**
     * @return array{oneByte: string[], twoBytes: string[]}
     */
    public function getCodePrefixes(): array
    {
        $oneBytePrefixes = [];
        $twoBytePrefixes = [];

        foreach (array_keys($this->getCodeLookup()) as $code) {
            $oneBytePrefixes[] = substr($code, 0, 2);
            $twoBytePrefixes[] = substr($code, 0, 4);
        }

        return [
            'oneByte' => array_values(array_unique($oneBytePrefixes)),
            'twoBytes' => array_values(array_unique($twoBytePrefixes)),
        ];
    }

    private function resolveButtonName(Device $device, string $code): ?string
    {
        return match ($code) {
            $device->code_a => 'A',
            $device->code_b => 'B',
            $device->code_c => 'C',
            $device->code_d => 'D',
            $device->code_e => 'E',
            $device->code_f => 'F',
            $device->code_ruka => 'Ruka',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function deviceCodes(Device $device): array
    {
        return [
            'A' => $device->code_a,
            'B' => $device->code_b,
            'C' => $device->code_c,
            'D' => $device->code_d,
            'E' => $device->code_e,
            'F' => $device->code_f,
            'Ruka' => $device->code_ruka,
        ];
    }
}
