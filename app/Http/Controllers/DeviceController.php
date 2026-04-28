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

        $rowErrors = [];
        $normalizedRows = [];

        foreach ($csvData as $index => $row) {
            $lineNumber = $index + 2;

            if (count(array_filter($row ?? [], fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if (count($row) < 8) {
                $rowErrors[] = "Riadok {$lineNumber}: očakávaných 8 stĺpcov, nájdených ".count($row).'.';

                continue;
            }

            $deviceNumber = trim((string) $row[0]);

            if ($deviceNumber === '') {
                $rowErrors[] = "Riadok {$lineNumber}: device_number je prázdny.";

                continue;
            }

            $codes = [];

            foreach (['code_a', 'code_b', 'code_c', 'code_d', 'code_e', 'code_f', 'code_ruka'] as $offset => $column) {
                $codes[$column] = $this->normalizeHexCode((string) $row[$offset + 1]);
            }

            $invalidCodeColumns = array_keys(array_filter(
                $codes,
                fn (?string $code): bool => $code === null,
            ));

            if ($invalidCodeColumns !== []) {
                $rowErrors[] = "Riadok {$lineNumber}: neplatný hex kód v stĺpcoch ".implode(', ', $invalidCodeColumns).'.';

                continue;
            }

            $normalizedRows[] = ['device_number' => $deviceNumber] + $codes;
        }

        if ($rowErrors !== []) {
            return response()->json([
                'message' => 'Import bol odmietnutý kvôli chybám v dátach.',
                'errors' => ['rows' => $rowErrors],
            ], 422);
        }

        DB::transaction(function () use ($normalizedRows): void {
            foreach ($normalizedRows as $row) {
                Device::updateOrCreate(
                    ['device_number' => $row['device_number']],
                    [
                        'code_a' => $row['code_a'],
                        'code_b' => $row['code_b'],
                        'code_c' => $row['code_c'],
                        'code_d' => $row['code_d'],
                        'code_e' => $row['code_e'],
                        'code_f' => $row['code_f'],
                        'code_ruka' => $row['code_ruka'],
                    ],
                );
            }
        });

        return response()->json([
            'message' => 'Import successful',
            'imported' => count($normalizedRows),
        ], 200);
    }

    private function normalizeHexCode(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^[0-9a-fA-F]+$/', $trimmed) !== 1) {
            return null;
        }

        return strtolower($trimmed);
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

            $tableExists = DB::connection('external')
                ->selectOne(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'SKDP_ParentZariadenie' LIMIT 1",
                );

            if ($tableExists === null) {
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
            ->orderBy('device_number')
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
        foreach ($devices as $device) {
            $deviceNumber = $this->normalizeImportedValue($device->UniqueId);

            if ($deviceNumber === '') {
                continue;
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
        }
    }

    private function normalizeImportedValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
