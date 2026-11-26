<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue and starter roles
|--------------------------------------------------------------------------
|
| Permissions are global (synced into the `permissions` table). Roles are per
| company: the starter roles below are copied into each new company, which can
| then grow its own. A person may hold several roles.
|
| Sensitive permissions cover salary, bank, tax identifiers and payroll exports.
|
*/

return [
    'permissions' => [
        // group => [key => [description, sensitive]]
        'company' => [
            'company.settings.view' => ['View company setup and the setup checklist', false],
            'company.settings.manage' => ['Change company profile, structure, regional and sign-in settings', false],
        ],
        'users' => [
            'users.view' => ['View users, their roles and status', false],
            'users.manage' => ['Invite users, change roles, suspend and remove access', false],
            'roles.manage' => ['Create and change roles and their permissions', false],
            'security.view' => ['Review company sign-in activity', false],
        ],
        'employees' => [
            'employees.view' => ['View employee records', false],
            'employees.manage' => ['Add and edit employees and change their status', false],
            'employees.import' => ['Import employees from a spreadsheet', false],
            'employees.documents.manage' => ['Upload and manage employee documents', false],
            'onboarding.manage' => ['Manage onboarding checklists and tasks', false],
        ],
        'leave' => [
            'leave.policies.manage' => ['Set up leave types and policies', false],
            'leave.balances.manage' => ['Adjust leave balances', false],
            'leave.requests.view' => ['View leave requests and the leave calendar', false],
            'leave.approve' => ['Approve or decline leave requests', false],
        ],
        'attendance' => [
            'attendance.view' => ['View attendance records', false],
            'attendance.manage' => ['Manage attendance settings and records', false],
            'attendance.corrections.approve' => ['Approve or decline attendance corrections', false],
        ],
        'contractors' => [
            'contractors.view' => ['View contractors, contracts and invoices', false],
            'contractors.manage' => ['Manage contractors, contracts and invoices', false],
            'contractors.invoices.approve' => ['Approve or reject contractor invoices', false],
        ],
        'payroll' => [
            'payroll.settings.manage' => ['Manage payroll settings, groups and employee pay', true],
            'payroll.view' => ['View payroll runs and results', true],
            'payroll.run' => ['Prepare and calculate payroll runs', true],
            'payroll.approve' => ['Approve and lock payroll runs', true],
            'payroll.salary.view' => ['See salary and pay amounts', true],
            'payroll.bank.view' => ['See bank account details', true],
            'payroll.tax_ids.view' => ['See tax identifiers', true],
            'payroll.export' => ['Create bank files and payroll exports', true],
            'payroll.reports.view' => ['View payroll reports', true],
            'payslips.manage' => ['Create and publish payslips', true],
        ],
        'reporting' => [
            'reports.hr.view' => ['View HR reports and dashboards', false],
            'activity.view' => ['View the activity history', false],
            'approvals.manage' => ['Reassign approvals and manage approval settings', false],
        ],
        'self' => [
            'self.profile.view' => ['View own profile', false],
            'self.leave.request' => ['Request own leave', false],
            'self.attendance.clock' => ['Clock in and out', false],
            'self.payslips.view' => ['View own payslips', false],
        ],
    ],

    'starter_roles' => [
        'owner' => [
            'name' => 'Owner / company administrator',
            'description' => 'Company-wide configuration and user management.',
            'permissions' => ['*'],
        ],
        'hr_admin' => [
            'name' => 'HR administrator',
            'description' => 'Employee lifecycle and HR operations. No salary, bank or tax access unless granted.',
            'permissions' => [
                'company.settings.view', 'users.view',
                'employees.*', 'onboarding.manage',
                'leave.*', 'attendance.*',
                'contractors.*',
                'reports.hr.view', 'activity.view', 'approvals.manage',
                'self.*',
            ],
        ],
        'payroll_admin' => [
            'name' => 'Payroll administrator',
            'description' => 'Pay details, payroll runs, payslips, exports and payroll reports.',
            'permissions' => ['company.settings.view', 'employees.view', 'payroll.*', 'payslips.manage', 'contractors.view', 'self.*'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Assigned team information and assigned approvals.',
            'permissions' => ['employees.view', 'leave.requests.view', 'leave.approve', 'attendance.view', 'attendance.corrections.approve', 'self.*'],
        ],
        'employee' => [
            'name' => 'Employee',
            'description' => 'Own permitted profile, requests, attendance and payslips.',
            'permissions' => ['self.*'],
        ],
    ],
];
