<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\MfaSettingsController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterCompanyController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Company\CompanyProfileController;
use App\Http\Controllers\Company\CurrentCompanyController;
use App\Http\Controllers\Company\PayrollCountryController;
use App\Http\Controllers\Company\SignInSettingsController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'store']);
Route::post('/auth/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/auth/sso/discover', [SsoController::class, 'discover'])->middleware('throttle:20,1');
Route::post('/auth/register-company', [RegisterCompanyController::class, 'store'])->middleware('throttle:5,1');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:6,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [SessionController::class, 'show']);
    Route::post('/auth/logout', [LoginController::class, 'destroy']);
    Route::post('/auth/logout-others', [LoginController::class, 'destroyOthers']);
    Route::get('/auth/sign-ins', [LoginController::class, 'activity']);
    Route::get('/auth/mfa', [MfaSettingsController::class, 'show']);
    Route::put('/auth/mfa', [MfaSettingsController::class, 'update']);

    // M.Y HRMS operations staff only.
    Route::middleware('platform:ops')->prefix('platform')->group(function (): void {
        Route::get('/companies', [PlatformCompanyController::class, 'index']);
        Route::post('/companies', [PlatformCompanyController::class, 'store']);
    });

    Route::middleware('company')->group(function (): void {
        Route::get('/company', [CurrentCompanyController::class, 'show']);

        Route::get('/company/profile', [CompanyProfileController::class, 'show']);
        Route::put('/company/profile', [CompanyProfileController::class, 'update']);

        Route::get('/company/payroll-countries', [PayrollCountryController::class, 'show']);
        Route::put('/company/payroll-countries', [PayrollCountryController::class, 'update']);

        Route::get('/company/sign-in-settings', [SignInSettingsController::class, 'show']);
        Route::put('/company/sign-in-settings', [SignInSettingsController::class, 'update']);
    });
});
