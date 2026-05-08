<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        return view('import');
    }

    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('csv_file');
        $csvData = array_map('str_getcsv', file($file->getPathname()));

        array_shift($csvData);

        Device::query()->delete();

        collect($csvData)
            ->filter(function (array $row): bool {
                return collect($row)
                    ->contains(fn (mixed $value): bool => $this->normalizeImportedValue($value) !== '');
            })
            ->sortBy(fn (array $row): string => Device::sortKeyForDeviceNumber(
                $this->normalizeImportedValue($row[0] ?? null),
            ))
            ->each(function (array $row): void {
                Device::create([
                    'device_number' => $this->normalizeImportedValue($row[0] ?? null),
                    'code_a' => $this->normalizeImportedValue($row[1] ?? null),
                    'code_b' => $this->normalizeImportedValue($row[2] ?? null),
                    'code_c' => $this->normalizeImportedValue($row[3] ?? null),
                    'code_d' => $this->normalizeImportedValue($row[4] ?? null),
                    'code_e' => $this->normalizeImportedValue($row[5] ?? null),
                    'code_f' => $this->normalizeImportedValue($row[6] ?? null),
                    'code_ruka' => $this->normalizeImportedValue($row[7] ?? null),
                ]);
            });

        return response()->json(['message' => 'Import successful'], 200);
    }

    public function loadExternalDb(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'db_file' => 'required|file|mimes:sqlite,db,sqlite3',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        $file = $request->file('db_file');
        $path = $file->storeAs('temp', uniqid('external_', true).'.sqlite');

        try {
            config(['database.connections.external' => [
                'driver' => 'sqlite',
                'database' => storage_path('app/private/'.$path),
            ]]);

            $hasSourceTable = DB::connection('external')
                ->selectOne(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'SKDP_ParentZariadenie' LIMIT 1",
                ) !== null;

            if (! $hasSourceTable) {
                return redirect()->back()->withErrors([
                    'db_file' => 'Externá databáza neobsahuje tabuľku SKDP_ParentZariadenie.',
                ]);
            }

            $devices = DB::connection('external')->table('SKDP_ParentZariadenie')->get();

            $this->storeExternalDevices($devices);
        } finally {
            DB::purge('external');
            Storage::delete($path);
        }

        return redirect()->back()->with('success', 'Externá databáza bola úspešne načítaná.');
    }

    public function showImportForm(): View
    {
        return view('import-external-db');
    }

    public function showDevices(): View
    {
        $devices = Device::query()
            ->ordered()
            ->paginate(24);

        $incompleteDevicesCount = Device::query()
            ->where('code_a', '')
            ->orWhere('code_b', '')
            ->orWhere('code_c', '')
            ->orWhere('code_d', '')
            ->orWhere('code_e', '')
            ->orWhere('code_f', '')
            ->orWhere('code_ruka', '')
            ->count();

        return view('devices.index', [
            'devices' => $devices,
            'devicesCount' => Device::query()->count(),
            'incompleteDevicesCount' => $incompleteDevicesCount,
        ]);
    }

    private function storeExternalDevices(Collection $devices): void
    {
        $devices
            ->sortBy(fn (object $device): string => Device::sortKeyForDeviceNumber(
                $this->normalizeImportedValue($device->UniqueId ?? null),
            ))
            ->each(function (object $device): void {
                $deviceNumber = $this->normalizeImportedValue($device->UniqueId);

                if ($deviceNumber === '') {
                    return;
                }

                Device::updateOrCreate(
                    ['device_number' => $deviceNumber],
                    [
                        'code_a' => $this->normalizeImportedValue($device->A_Code),
                        'code_b' => $this->normalizeImportedValue($device->B_Code),
                        'code_c' => $this->normalizeImportedValue($device->C_Code),
                        'code_d' => $this->normalizeImportedValue($device->D_Code),
                        'code_e' => $this->normalizeImportedValue($device->E_Code),
                        'code_f' => $this->normalizeImportedValue($device->F_Code),
                        'code_ruka' => $this->normalizeImportedValue($device->Ruka_Code),
                    ]
                );
            });
    }

    private function normalizeImportedValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
