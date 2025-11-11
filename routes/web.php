<?php

use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware('superadmin.basic')->prefix('superadmin')->group(function () {
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('backups/{filename}', [BackupController::class, 'download'])->name('backups.download');
});

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|storage).*$');
