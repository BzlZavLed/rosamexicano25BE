<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class BackupController extends Controller
{
    public function index()
    {
        $directory = storage_path('app/backups');
        $files = [];
        if (File::exists($directory)) {
            $files = collect(File::files($directory))
                ->sortByDesc(fn ($file) => $file->getCTime())
                ->map(function ($file) {
                    return [
                        'name' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'created_at' => $file->getCTime(),
                    ];
                })->values()->all();
        }

        return view('backups.index', ['files' => $files]);
    }

    public function download(string $filename)
    {
        $safeName = basename($filename);
        $path = storage_path('app/backups/'.$safeName);
        abort_unless(File::exists($path), 404);

        return response()->download($path, $safeName, [
            'Content-Type' => 'application/sql',
        ]);
    }
}
