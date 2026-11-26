<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\MfaSettingsController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Company\CurrentCompanyController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'store']);
Route::post('/auth/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [SessionController::class, 'show']);
    Route::post('/auth/logout', [LoginController::class, 'destroy']);
    Route::post('/auth/logout-others', [LoginController::class, 'destroyOthers']);
    Route::get('/auth/sign-ins', [LoginController::class, 'activity']);
    Route::get('/auth/mfa', [MfaSettingsController::class, 'show']);
    Route::put('/auth/mfa', [MfaSettingsController::class, 'update']);

    Route::middleware('company')->group(function (): void {
        Route::get('/company', [CurrentCompanyController::class, 'show']);
    });
});
