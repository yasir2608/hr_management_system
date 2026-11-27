<?php

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
        // Filled in by the country rules (chunks 8.10 to 8.12).
    ],
];
