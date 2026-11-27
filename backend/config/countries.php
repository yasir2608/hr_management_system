<?php

/*
|--------------------------------------------------------------------------
| Country-specific employee details
|--------------------------------------------------------------------------
|
| The identifiers and tax details each payroll country needs on an employee
| record. Used by forms, validation and the spreadsheet import. `required`
| means required before the employee is payroll-ready, not to save the record.
|
*/

return [
    'PK' => [
        'name' => 'Pakistan',
        'currency' => 'PKR',
        'fields' => [
            'cnic' => ['label' => 'CNIC number', 'required' => true, 'regex' => '/^\d{5}-?\d{7}-?\d$/', 'hint' => '13 digits, e.g. 35202-1234567-1'],
            'ntn' => ['label' => 'National tax number (NTN)', 'required' => false, 'regex' => '/^\d{7}-?\d?$/', 'hint' => '7 or 8 digits'],
            'eobi_number' => ['label' => 'EOBI registration number', 'required' => false, 'regex' => null, 'hint' => null],
            'province' => ['label' => 'Province (for social security)', 'required' => true, 'options' => ['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad'], 'regex' => null, 'hint' => null],
        ],
    ],
    'GB' => [
        'name' => 'United Kingdom',
        'currency' => 'GBP',
        'fields' => [
            'ni_number' => ['label' => 'National Insurance number', 'required' => true, 'regex' => '/^[A-CEGHJ-PR-TW-Z]{2}\s?\d{2}\s?\d{2}\s?\d{2}\s?[A-D]$/i', 'hint' => 'e.g. AB 12 34 56 C'],
            'tax_code' => ['label' => 'Tax code', 'required' => true, 'regex' => '/^(K?\d{1,4}[LMNTX]?|BR|D0|D1|NT|0T)(\s?(W1|M1|X))?$/i', 'hint' => 'e.g. 1257L'],
            'student_loan_plan' => ['label' => 'Student loan plan', 'required' => false, 'options' => ['none', 'plan_1', 'plan_2', 'plan_4', 'plan_5', 'postgraduate'], 'regex' => null, 'hint' => null],
            'pension_opt_out' => ['label' => 'Opted out of workplace pension', 'required' => false, 'options' => ['no', 'yes'], 'regex' => null, 'hint' => null],
        ],
    ],
    'US' => [
        'name' => 'United States',
        'currency' => 'USD',
        'fields' => [
            'ssn' => ['label' => 'Social Security number', 'required' => true, 'regex' => '/^(?!000|666|9\d\d)\d{3}-?(?!00)\d{2}-?(?!0000)\d{4}$/', 'hint' => 'e.g. 123-45-6789'],
            'filing_status' => ['label' => 'W-4 filing status', 'required' => true, 'options' => ['single', 'married_jointly', 'head_of_household'], 'regex' => null, 'hint' => null],
            'work_state' => ['label' => 'Work state', 'required' => true, 'options' => ['CA', 'NY', 'TX', 'FL', 'WA', 'IL', 'MA', 'Other'], 'regex' => null, 'hint' => 'Supported states for withholding'],
            'w4_extra_withholding' => ['label' => 'Extra withholding per pay period', 'required' => false, 'regex' => '/^\d+(\.\d{1,2})?$/', 'hint' => 'USD amount'],
        ],
    ],
];
