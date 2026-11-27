<?php

namespace App\Payroll\Calculation;

/**
 * What country rules worked out. Employee lines are taken from pay; employer lines add to the company's cost.
 */
final class RulesResult
{
    /** @var list<array{code: string, label: string, category: string, amount: int, explanation: string}> */
    public array $employee = [];

    /** @var list<array{code: string, label: string, category: string, amount: int, explanation: string}> */
    public array $employer = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $blocking = [];

    /** @var list<string> */
    public array $notes = [];

    /**
     * @param  string  $category  tax | employee_contribution
     */
    public function employeeLine(string $code, string $label, int $amount, string $explanation, string $category = 'tax'): self
    {
        $this->employee[] = ['code' => $code, 'label' => $label, 'category' => $category, 'amount' => $amount, 'explanation' => $explanation];

        return $this;
    }

    public function employerLine(string $code, string $label, int $amount, string $explanation): self
    {
        $this->employer[] = ['code' => $code, 'label' => $label, 'category' => 'employer_contribution', 'amount' => $amount, 'explanation' => $explanation];

        return $this;
    }
}
