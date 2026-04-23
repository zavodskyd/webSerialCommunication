<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;

class SerialCommunication extends Component
{
    public $result = '';
    public $devices;
    public $activeCode = null;

    public function mount()
    {
        $this->devices = Device::all();
    }

    public function render()
    {
        return view('livewire.serial-communication');
    }

    public function updateActiveCode($code)
    {
        $this->activeCode = $code;
    }

    public function checkCode($code)
    {
        $device = Device::where('code_a', $code)
            ->orWhere('code_b', $code)
            ->orWhere('code_c', $code)
            ->orWhere('code_d', $code)
            ->orWhere('code_e', $code)
            ->orWhere('code_f', $code)
            ->orWhere('code_ruka', $code)
            ->first();

        if ($device) {
            $this->updateActiveCode($code);
            // Určte názov stlačeného tlačidla na základe kódu
            $buttonName = '';
            switch ($code) {
                case $device->code_a:
                    $buttonName = 'A';
                    break;
                case $device->code_b:
                    $buttonName = 'B';
                    break;
                case $device->code_c:
                    $buttonName = 'C';
                    break;
                case $device->code_d:
                    $buttonName = 'D';
                    break;
                case $device->code_e:
                    $buttonName = 'E';
                    break;
                case $device->code_f:
                    $buttonName = 'F';
                    break;
                case $device->code_ruka:
                    $buttonName = 'Ruka';
                    break;
            }

            $this->result = "Názov zariadenia: " . $device->device_number . ", Stlačená hodnota: " . $buttonName . " (" . $code . ")";
        } else {
            $this->result = "Kód " . $code . " sa nenašiel";
        }
    }
}
