<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    public function index(): View
    {
        return view('import');
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('csv_file');
        $csvData = array_map('str_getcsv', file($file->getPathname()));

        // Odstránenie hlavičky
        $headers = array_shift($csvData);

        foreach ($csvData as $row) {
            Device::updateOrCreate(
                ['device_number' => $row[0]],
                [
                    'code_a' => $row[1],
                    'code_b' => $row[2],
                    'code_c' => $row[3],
                    'code_d' => $row[4],
                    'code_e' => $row[5],
                    'code_f' => $row[6],
                    'code_ruka' => $row[7],
                ]
            );
        }

        return response()->json(['message' => 'Import successful'], 200);
    }

    public function loadExternalDb(Request $request)
    {
        // dd($request->file('db_file')->getMimeType());
        $validator = Validator::make($request->all(), [
            'db_file' => 'required|file|mimes:sqlite,db,sqlite3',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        $file = $request->file('db_file');
        $path = $file->storeAs('temp', 'external.sqlite');

        config(['database.connections.external' => [
            'driver' => 'sqlite',
            'database' => storage_path('app/private/' . $path),
        ]]);

        $devices = DB::connection('external')->table('SKDP_ParentZariadenie')->get();

        foreach ($devices as $device) {
            Device::updateOrCreate(
                ['device_number' => $device->UniqueId],
                [
                    'code_a' => $device->A_Code,
                    'code_b' => $device->B_Code,
                    'code_c' => $device->C_Code,
                    'code_d' => $device->D_Code,
                    'code_e' => $device->E_Code,
                    'code_f' => $device->F_Code,
                    'code_ruka' => $device->Ruka_Code,
                ]
            );
        }

        return redirect()->back()->with('success', 'Externá databáza bola úspešne načítaná.');
    }

    public function showImportForm()
    {
        return view('import-external-db');
    }
}
