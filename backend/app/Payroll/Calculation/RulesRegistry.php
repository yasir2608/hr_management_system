<?php

namespace App\Payroll\Calculation;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Finds the country rules in force for a pay period, by the period's end date. Versions are listed in
 * config/payroll_rules.php; a period with no matching version is reported, never calculated with guessed values.
 */
class RulesRegistry
{
    /** @var array<string, list<array{class: class-string<CountryRules>, from: string, to: ?string}>> */
    private array $rules;

    /**
     * @param  array<string, list<array{class: class-string<CountryRules>, from: string, to: ?string}>>|null  $rules
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? config('payroll_rules.versions', []);
        $this->assertNoOverlaps();
    }

    public function for(string $country, CarbonImmutable $periodEnd): ?CountryRules
    {
        foreach ($this->rules[$country] ?? [] as $version) {
            if ($this->covers($version, $periodEnd)) {
                return app($version['class']);
            }
        }

        return null;
    }

    /**
     * Every configured version, newest first per country.
     *
     * @return list<array{country: string, version: string, from: string, to: ?string, status: string, figures: list<array{label: string, value: string}>}>
     */
    public function versions(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $list = [];

        foreach ($this->rules as $country => $versions) {
            foreach ($versions as $version) {
                /** @var CountryRules $rules */
                $rules = app($version['class']);
                $list[] = [
                    'country' => $country,
                    'version' => $rules->version(),
                    'from' => $version['from'],
                    'to' => $version['to'],
                    'status' => $this->covers($version, $today) ? 'current' : (CarbonImmutable::parse($version['from'])->gt($today) ? 'upcoming' : 'past'),
                    'figures' => $rules->figures(),
                ];
            }
        }

        usort($list, fn (array $a, array $b): int => [$a['country'], $b['from']] <=> [$b['country'], $a['from']]);

        return $list;
    }

    /**
     * @param  array{from: string, to: ?string}  $version
     */
    private function covers(array $version, CarbonImmutable $date): bool
    {
        return $date->gte(CarbonImmutable::parse($version['from'])) && ($version['to'] === null || $date->lte(CarbonImmutable::parse($version['to'])));
    }

    /**
     * Two versions of one country covering the same day would make the result depend on list order.
     */
    private function assertNoOverlaps(): void
    {
        foreach ($this->rules as $country => $versions) {
            $sorted = collect($versions)->sortBy('from')->values();
            foreach ($sorted as $i => $version) {
                $next = $sorted[$i + 1] ?? null;
                if ($next !== null && ($version['to'] === null || CarbonImmutable::parse($version['to'])->gte(CarbonImmutable::parse($next['from'])))) {
                    throw new LogicException("Payroll rule versions for {$country} overlap: one starting {$version['from']} and one starting {$next['from']}.");
                }
            }
        }
    }
}
