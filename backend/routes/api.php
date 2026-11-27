<?php

use App\Http\Controllers\Access\CompanySupportAccessController;
use App\Http\Controllers\Access\InvitationController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserAccessController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\Activity\ActivityIntegrityController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Attendance\AttendanceExceptionController;
use App\Http\Controllers\Attendance\AttendanceHistoryController;
use App\Http\Controllers\Attendance\ClockController;
use App\Http\Controllers\Attendance\CorrectionController;
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
use App\Http\Controllers\Contractors\ContractController;
use App\Http\Controllers\Contractors\ContractorController;
use App\Http\Controllers\Contractors\ContractorFieldController;
use App\Http\Controllers\Employees\EmployeeChangeController;
use App\Http\Controllers\Employees\EmployeeController;
use App\Http\Controllers\Employees\EmployeeDocumentController;
use App\Http\Controllers\Employees\EmployeeImportController;
use App\Http\Controllers\Employees\SelfServiceController;
use App\Http\Controllers\Leave\LeaveBalanceController;
use App\Http\Controllers\Leave\LeaveCalendarController;
use App\Http\Controllers\Leave\LeavePolicyController;
use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Http\Controllers\Leave\UnpaidLeaveController;
use App\Http\Controllers\Onboarding\ActivationController;
use App\Http\Controllers\Onboarding\EmployeeOnboardingController;
use App\Http\Controllers\Onboarding\OnboardingTaskController;
use App\Http\Controllers\Onboarding\OnboardingTemplateController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\LocationController;
use App\Http\Controllers\Organization\TeamController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\Platform\SupportAccessController;
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

    // Support access: any M.Y HRMS staff role (ops or support).
    Route::middleware('platform')->prefix('platform')->group(function (): void {
        Route::get('/companies/directory', [PlatformCompanyController::class, 'index']);
        Route::get('/support-access', [SupportAccessController::class, 'index']);
        Route::post('/support-access', [SupportAccessController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/support-access/{grant}/end', [SupportAccessController::class, 'end']);
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

        // Self-service
        Route::middleware('permission:self.profile.view')->prefix('me')->group(function (): void {
            Route::get('/profile', [SelfServiceController::class, 'show']);
            Route::post('/update-requests', [SelfServiceController::class, 'requestUpdate'])->middleware('throttle:10,1');
            Route::post('/update-requests/{request}/withdraw', [SelfServiceController::class, 'withdraw']);
        });
        Route::get('/employees/{employee}/update-requests', [SelfServiceController::class, 'index'])->whereUlid('employee');
        Route::post('/update-requests/{request}/review', [SelfServiceController::class, 'review'])->middleware('permission:employees.manage');

        // Employees
        Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view,employees.manage');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->whereUlid('employee');
        Route::put('/employees/{employee}', [EmployeeChangeController::class, 'updateDetails'])->whereUlid('employee');
        Route::post('/employees/{employee}/changes', [EmployeeChangeController::class, 'recordChange'])->whereUlid('employee');
        Route::post('/employees/{employee}/status', [EmployeeChangeController::class, 'changeStatus'])->whereUlid('employee');
        Route::get('/employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->whereUlid('employee');
        Route::post('/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->whereUlid('employee')->middleware('throttle:30,1');
        Route::get('/employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->whereUlid(['employee', 'document']);
        Route::delete('/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->whereUlid(['employee', 'document']);
        Route::put('/employees/{employee}/payroll-details', [EmployeeChangeController::class, 'updatePayrollDetails'])->whereUlid('employee');
        Route::get('/employees/form-options', [EmployeeController::class, 'formOptions'])->middleware('permission:employees.manage,payroll.settings.manage');
        Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:employees.manage');

        // Employee import
        Route::middleware('permission:employees.import')->prefix('employee-imports')->group(function (): void {
            Route::get('/columns', [EmployeeImportController::class, 'columns']);
            Route::get('/template', [EmployeeImportController::class, 'template']);
            Route::post('/', [EmployeeImportController::class, 'store'])->middleware('throttle:20,1');
            Route::get('/{import}', [EmployeeImportController::class, 'show'])->whereUlid('import');
            Route::post('/{import}/cancel', [EmployeeImportController::class, 'cancel'])->whereUlid('import');
            Route::post('/{import}/validate', [EmployeeImportController::class, 'revalidate'])->whereUlid('import');
            Route::patch('/{import}/rows/{row}', [EmployeeImportController::class, 'updateRow'])->whereUlid('import')->whereNumber('row');
            Route::post('/{import}/rows/{row}/toggle', [EmployeeImportController::class, 'toggleRow'])->whereUlid('import')->whereNumber('row');
            Route::get('/{import}/errors', [EmployeeImportController::class, 'errors'])->whereUlid('import');
            Route::post('/{import}/confirm', [EmployeeImportController::class, 'confirm'])->whereUlid('import');
            Route::get('/', [EmployeeImportController::class, 'index']);
        });

        // Onboarding
        Route::middleware('permission:onboarding.manage')->prefix('onboarding')->group(function (): void {
            Route::get('/templates', [OnboardingTemplateController::class, 'index']);
            Route::post('/templates', [OnboardingTemplateController::class, 'store']);
            Route::put('/templates/{template}', [OnboardingTemplateController::class, 'update']);
            Route::post('/templates/{template}/default', [OnboardingTemplateController::class, 'makeDefault']);
            Route::delete('/templates/{template}', [OnboardingTemplateController::class, 'destroy']);
            Route::get('/employees', [OnboardingTaskController::class, 'overview']);
            Route::post('/employees/{employee}/start', [OnboardingTaskController::class, 'start'])->whereUlid('employee');
            Route::get('/employees/{employee}/activation', [ActivationController::class, 'show'])->whereUlid('employee');
            Route::post('/employees/{employee}/activate', [ActivationController::class, 'activate'])->whereUlid('employee')->middleware('permission:employees.manage');
        });
        Route::get('/onboarding/employees/{employee}/tasks', [OnboardingTaskController::class, 'forEmployee'])->whereUlid('employee');
        Route::get('/onboarding/my-tasks', [OnboardingTaskController::class, 'mine']);
        Route::patch('/onboarding/tasks/{task}', [OnboardingTaskController::class, 'update']);
        Route::get('/onboarding/tasks/{task}/submission', [EmployeeOnboardingController::class, 'submission']);
        Route::post('/onboarding/tasks/{task}/review', [EmployeeOnboardingController::class, 'review']);
        Route::get('/me/onboarding', [EmployeeOnboardingController::class, 'mine']);
        Route::post('/me/onboarding/tasks/{task}', [EmployeeOnboardingController::class, 'submit'])->middleware('throttle:20,1');

        // Approvals (shared by leave, attendance, invoices and payroll)
        Route::get('/approvals/{approval}', [ApprovalController::class, 'show'])->whereUlid('approval');
        Route::post('/approvals/{approval}/decide', [ApprovalController::class, 'decide'])->whereUlid('approval');
        Route::post('/approvals/{approval}/info', [ApprovalController::class, 'provideInfo'])->whereUlid('approval');
        Route::post('/approvals/{approval}/reassign', [ApprovalController::class, 'reassign'])->whereUlid('approval');

        // Leave
        Route::get('/leave/types', [LeaveTypeController::class, 'index']);
        Route::get('/me/leave/balances', [LeaveBalanceController::class, 'mine'])->middleware('permission:self.leave.request');
        Route::middleware('permission:self.leave.request')->group(function (): void {
            Route::get('/me/leave/requests', [LeaveRequestController::class, 'mine']);
            Route::post('/me/leave/requests', [LeaveRequestController::class, 'storeMine'])->middleware('throttle:20,1');
            Route::post('/me/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'updateMine'])->whereUlid('leaveRequest');
            Route::post('/me/leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancelMine'])->whereUlid('leaveRequest');
        });
        Route::post('/leave/days-preview', [LeaveRequestController::class, 'preview']);
        Route::get('/leave/unpaid', UnpaidLeaveController::class)->middleware('permission:payroll.run,payroll.view,leave.balances.manage');
        Route::get('/leave/calendar', [LeaveCalendarController::class, 'month'])->middleware('permission:leave.requests.view,leave.approve,self.leave.request');
        Route::get('/leave/employees/{employee}/requests', [LeaveCalendarController::class, 'history'])->whereUlid('employee');
        Route::get('/leave/requests/{leaveRequest}/context', [LeaveRequestController::class, 'context'])->whereUlid('leaveRequest');
        Route::post('/leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancelFor'])->whereUlid('leaveRequest')->middleware('permission:leave.balances.manage');
        Route::get('/leave/requests', [LeaveRequestController::class, 'index'])->middleware('permission:leave.requests.view,leave.approve');
        Route::post('/leave/employees/{employee}/requests', [LeaveRequestController::class, 'storeFor'])->whereUlid('employee')->middleware('permission:leave.balances.manage');
        Route::get('/leave/employees/{employee}/balances', [LeaveBalanceController::class, 'forEmployee'])->whereUlid('employee');
        Route::get('/leave/balances', [LeaveBalanceController::class, 'overview'])->middleware('permission:leave.requests.view,leave.balances.manage');
        Route::post('/leave/balances/adjustments', [LeaveBalanceController::class, 'adjust'])->middleware('permission:leave.balances.manage');
        Route::middleware('permission:leave.policies.manage')->prefix('leave')->group(function (): void {
            Route::post('/types', [LeaveTypeController::class, 'store']);
            Route::put('/types/{leaveType}', [LeaveTypeController::class, 'update']);
            Route::post('/types/starters', [LeaveTypeController::class, 'addStarters']);
            Route::get('/policies', [LeavePolicyController::class, 'index']);
            Route::post('/policies', [LeavePolicyController::class, 'store']);
            Route::post('/policies/{policy}/versions', [LeavePolicyController::class, 'newVersion']);
            Route::get('/policies/preview', [LeavePolicyController::class, 'preview']);
        });

        // Attendance
        Route::middleware('permission:self.attendance.clock')->prefix('me/clock')->group(function (): void {
            Route::get('/', [ClockController::class, 'show']);
            Route::post('/in', [ClockController::class, 'in'])->middleware('throttle:20,1');
            Route::post('/out', [ClockController::class, 'out'])->middleware('throttle:20,1');
        });

        Route::get('/me/attendance', [AttendanceHistoryController::class, 'mine'])->middleware('permission:self.attendance.clock');
        Route::middleware('permission:self.attendance.clock')->group(function (): void {
            Route::get('/me/attendance/corrections', [CorrectionController::class, 'mine']);
            Route::post('/me/attendance/corrections', [CorrectionController::class, 'store'])->middleware('throttle:20,1');
        });
        Route::get('/attendance/records', [AttendanceHistoryController::class, 'index'])->middleware('permission:attendance.view,attendance.manage');
        Route::get('/attendance/exceptions', AttendanceExceptionController::class)->middleware('permission:attendance.view,attendance.manage');
        Route::get('/attendance/corrections', [CorrectionController::class, 'index'])->middleware('permission:attendance.view,attendance.corrections.approve');
        Route::get('/attendance/records/{record}/corrections', [CorrectionController::class, 'history'])->whereUlid('record');
        Route::get('/attendance/employees/{employee}', [AttendanceHistoryController::class, 'forEmployee'])->whereUlid('employee');

        // Contractors
        Route::middleware('permission:contractors.view,contractors.manage')->group(function (): void {
            Route::get('/contractors', [ContractorController::class, 'index']);
            Route::get('/contractors/{contractor}', [ContractorController::class, 'show'])->whereUlid('contractor');
            Route::get('/contractors/{contractor}/contracts', [ContractController::class, 'index'])->whereUlid('contractor');
            Route::get('/contractors/{contractor}/contracts/{contract}/signed', [ContractController::class, 'download'])->whereUlid(['contractor', 'contract']);
        });
        Route::middleware('permission:contractors.manage')->group(function (): void {
            Route::post('/contractors', [ContractorController::class, 'store']);
            Route::put('/contractors/{contractor}', [ContractorController::class, 'update'])->whereUlid('contractor');
            Route::put('/contractor-fields', [ContractorFieldController::class, 'update']);
            Route::post('/contractors/{contractor}/contracts', [ContractController::class, 'store'])->whereUlid('contractor');
            Route::post('/contractors/{contractor}/contracts/{contract}', [ContractController::class, 'update'])->whereUlid(['contractor', 'contract']);
        });

        // Activity history
        Route::get('/activity/integrity', ActivityIntegrityController::class)->middleware('permission:activity.view');

        // Users with access to the company
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::get('/invitations', [InvitationController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::get('/support-access', [CompanySupportAccessController::class, 'index'])->middleware('permission:users.view,users.manage');
        Route::middleware('permission:users.manage')->group(function (): void {
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/resend', [UserAccessController::class, 'resendInvitation']);
            Route::delete('/invitations/{invitation}', [UserAccessController::class, 'revokeInvitation']);
            Route::post('/users/{membership}/roles', [UserAccessController::class, 'addRole']);
            Route::delete('/users/{membership}/roles/{assignment}', [UserAccessController::class, 'removeRole']);
            Route::post('/users/{membership}/suspend', [UserAccessController::class, 'suspend']);
            Route::post('/users/{membership}/reactivate', [UserAccessController::class, 'reactivate']);
            Route::post('/users/{membership}/remove', [UserAccessController::class, 'remove']);
            Route::post('/support-access/{grant}/revoke', [CompanySupportAccessController::class, 'revoke']);
        });

        // Roles and permissions
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:users.view,users.manage,roles.manage');
        Route::middleware('permission:roles.manage')->group(function (): void {
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}', [RoleController::class, 'update']);
        });
    });
});
