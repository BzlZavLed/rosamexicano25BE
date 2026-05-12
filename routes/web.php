<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\WebAuthn\WebAuthnLoginController;
use App\Http\Controllers\WebAuthn\WebAuthnRegisterController;
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

Route::prefix('api')->middleware('web')->group(function () {
    Route::post('/auth/passkey/login/options', [WebAuthnLoginController::class, 'options'])->name('api.auth.passkey.login.options');
    Route::post('/auth/passkey/login', [WebAuthnLoginController::class, 'login'])->name('api.auth.passkey.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/passkey/options', [WebAuthnRegisterController::class, 'options'])->name('api.auth.passkey.options');
        Route::post('/auth/passkey/register', [WebAuthnRegisterController::class, 'register'])->name('api.auth.passkey.register');
    });
});

Route::view('/', 'landing');

Route::view('/{any}', 'app')
    ->where('any', '^(?!(api|sanctum|storage|build)(/|$)).*$');
