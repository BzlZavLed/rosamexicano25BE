<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\SuperAdminAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('superadmin')->group(function () {
    Route::get('login', [SuperAdminAuthController::class, 'showLogin'])->name('superadmin.login');
    Route::post('login', [SuperAdminAuthController::class, 'authenticate'])->name('superadmin.login.submit');
    Route::post('logout', [SuperAdminAuthController::class, 'logout'])->name('superadmin.logout');

    Route::middleware('superadmin.auth')->group(function () {
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::get('backups/{filename}', [BackupController::class, 'download'])->name('backups.download');
    });
});

Route::view('/', 'landing');

Route::view('/{any}', 'app')
    ->where('any', '^(?!api|sanctum|storage).*$');
