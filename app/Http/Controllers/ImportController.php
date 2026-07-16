<?php

/*
App\Models\User::create([
     'name' => 'Marcelo',
     'email' => 'marcelo@test.com',
     'password' => bcrypt('12345678'),
 ]);
*/

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Import;
use App\Jobs\ProcessImportJob;
use App\Services\BankParsers\BankDetector;

class ImportController extends Controller
{
    public function index()
    {
        return Import::with('user')
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');

        $fileHash = hash_file(
            'sha256',
            $file->getRealPath()
        );

        $alreadyImported = Import::where('user_id', 1)
            ->where('file_hash', $fileHash)
            ->where('status', '!=', 'failed')
            ->exists();

        if ($alreadyImported) {
            return response()->json([
                'message' => 'Este arquivo já foi importado anteriormente.'
            ], 422);
        }

        $content = file_get_contents(
            $file->getRealPath()
        );

        $bank = (new BankDetector())
            ->detect($content);

        $path = $file->store('imports');

        $import = Import::create([
            'user_id' => 1, // depois trocar por auth()->id()
            'source' => 'csv',
            'filename' => $path,
            'bank' => $bank,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash' => $fileHash,
            'status' => 'pending',
        ]);

        ProcessImportJob::dispatch($import);

        return response()->json([
            'message' => 'Arquivo enviado com sucesso',
            'import' => $import,
        ]);
    }
}
