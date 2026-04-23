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
            ->orderBy('device_number')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.serial-communication');
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
}
