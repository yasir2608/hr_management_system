<?php

/*
|--------------------------------------------------------------------------
| Why each required employee field is needed
|--------------------------------------------------------------------------
|
| Shown next to required fields on the add-employee form and in import errors,
| so people understand why the product asks for something.
|
*/

return [
    'first_name' => 'Used on payslips, documents and throughout M.Y HRMS.',
    'last_name' => 'Used on payslips, documents and throughout M.Y HRMS.',
    'start_date' => 'Decides leave entitlement, the first pay period and length of service.',
    'job_title' => 'Shown in the directory and on employment records.',
    'work_country' => 'Decides which payroll rules, tax details and reports apply.',
    'employee_number' => 'Identifies the person on payroll reports. Leave empty to create one automatically.',
    'work_email' => 'Needed to invite the employee to M.Y HRMS. Optional if they will not sign in.',
];
