<?php

namespace App\Payroll\Calculation;

use Carbon\CarbonImmutable;

/**
 * Finds the country rules in force for a pay period. Rules are versioned by date (see config/payroll_rules.php);
 * a period with no matching rules is reported, never calculated with guessed values.
 */
class RulesRegistry
{
    /**
     * @param  array<string, list<array{class: class-string<CountryRules>, from: string, to: ?string}>>|null  $rules
     */
    public function __construct(private ?array $rules = null)
    {
        $this->rules ??= config('payroll_rules.versions', []);
    }

    public function for(string $country, CarbonImmutable $periodEnd): ?CountryRules
    {
        foreach ($this->rules[$country] ?? [] as $version) {
            $from = CarbonImmutable::parse($version['from']);
            $to = $version['to'] === null ? null : CarbonImmutable::parse($version['to']);

            if ($periodEnd->gte($from) && ($to === null || $periodEnd->lte($to))) {
                return app($version['class']);
            }
        }

        return null;
    }
}
