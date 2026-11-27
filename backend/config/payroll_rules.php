<?php

use App\Payroll\Rules\Pakistan2025;

/*
|--------------------------------------------------------------------------
| Country payroll rule versions
|--------------------------------------------------------------------------
|
| Which rules apply to a pay period, by the period's end date. A run uses the
| version in force for its period and stores the version name once approved.
| A period with no version here cannot be calculated for that country.
|
| The figures inside each rule class are demo values based on published rates.
| They must be checked by payroll specialists before real use.
|
*/

return [
    'versions' => [
        // Tax year July to June. Open-ended until the next year's figures are added; later periods get a warning.
        'PK' => [
            ['class' => Pakistan2025::class, 'from' => '2025-07-01', 'to' => null],
        ],
    ],
];
