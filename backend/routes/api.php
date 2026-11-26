<?php

use App\Http\Controllers\Access\InvitationController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserAccessController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\Auth\AcceptInvitationController;
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
use App\Http\Controllers\Company\PreferencesController;
use App\Http\Controllers\Company\RegionalSettingsController;
use App\Http\Controllers\Company\SetupChecklistController;
use App\Http\Controllers\Company\SignInSettingsController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\LocationController;
use App\Http\Controllers\Organization\TeamController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'store']);
Route::post('/auth/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/auth/sso/discover', [SsoController::class, 'discover'])->middleware('throttle:20,1');
Route::post('/auth/register-company', [RegisterCompanyController::class, 'store'])->middleware('throttle:5,1');
Route::get('/invitations/accept', [AcceptInvitationController::class, 'show'])->middleware('throttle:30,1');
Route::post('/invitations/accept', [AcceptInvitationController::class, 'store'])->middleware('throttle:10,1');
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
        Route::get('/search', SearchController::class)->middleware('throttle:60,1');

        // Company setup
        Route::middleware('permission:company.settings.view,company.settings.manage')->group(function (): void {
            Route::get('/company/setup-checklist', [SetupChecklistController::class, 'show']);
            Route::get('/company/profile', [CompanyProfileController::class, 'show']);
            Route::get('/company/regional-settings', [RegionalSettingsController::class, 'show']);
            Route::get('/company/preferences', [PreferencesController::class, 'show']);
            Route::get('/company/payroll-countries', [PayrollCountryController::class, 'show']);
            Route::get('/company/sign-in-settings', [SignInSettingsController::class, 'show']);
        });

        Route::middleware('permission:company.settings.manage')->group(function (): void {
            Route::put('/company/profile', [CompanyProfileController::class, 'update']);
            Route::put('/company/regional-settings', [RegionalSettingsController::class, 'update']);
            Route::put('/company/preferences', [PreferencesController::class, 'update']);
            Route::post('/company/preferences/skip', [PreferencesController::class, 'skip']);
            Route::put('/company/payroll-countries', [PayrollCountryController::class, 'update']);
            Route::put('/company/sign-in-settings', [SignInSettingsController::class, 'update']);

            Route::apiResource('locations', LocationController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('departments', DepartmentController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('teams', TeamController::class)->only(['store', 'update', 'destroy']);
        });

        // Lists used by forms and filters across the app.
        Route::middleware('permission:company.settings.view,company.settings.manage,employees.view,users.view')->group(function (): void {
            Route::get('/locations', [LocationController::class, 'index']);
            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::get('/teams', [TeamController::class, 'index']);
        });

        // Users with access to the company
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::get('/invitations', [InvitationController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::middleware('permission:users.manage')->group(function (): void {
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/resend', [UserAccessController::class, 'resendInvitation']);
            Route::delete('/invitations/{invitation}', [UserAccessController::class, 'revokeInvitation']);
            Route::post('/users/{membership}/roles', [UserAccessController::class, 'addRole']);
            Route::delete('/users/{membership}/roles/{assignment}', [UserAccessController::class, 'removeRole']);
            Route::post('/users/{membership}/suspend', [UserAccessController::class, 'suspend']);
            Route::post('/users/{membership}/reactivate', [UserAccessController::class, 'reactivate']);
            Route::post('/users/{membership}/remove', [UserAccessController::class, 'remove']);
        });

        // Roles and permissions
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:users.view,users.manage,roles.manage');
        Route::middleware('permission:roles.manage')->group(function (): void {
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}', [RoleController::class, 'update']);
        });
    });
});
