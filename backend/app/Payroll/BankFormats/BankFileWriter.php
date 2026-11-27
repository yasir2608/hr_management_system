<?php

namespace App\Payroll\BankFormats;

use App\Models\BankFormat;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollRun;
use App\Payroll\Calculation\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Writes the bank file for prepared payments in the format's layout. Each format here is listed in the catalog;
 * nothing else can be written.
 */
class BankFileWriter
{
    /**
     * @param  list<array{employee_id: string, name: string, employee_number: ?string, amount_cents: int, account: EmployeeBankAccount}>  $payments
     * @return array{name: string, content: string, mime: string}
     */
    public function write(BankFormat $format, PayrollRun $run, array $payments, ?CarbonImmutable $createdAt = null): array
    {
        $createdAt ??= CarbonImmutable::now();
        $content = match ($format->template) {
            'generic_csv' => $this->genericCsv($format, $run, $payments),
            'uk_bacs_csv' => $this->bacsCsv($format, $run, $payments),
            'us_nacha_ach' => $this->nacha($format, $run, $payments, $createdAt),
            'pk_ibft_csv' => $this->ibftCsv($format, $run, $payments),
        };
        $extension = BankFormatCatalog::get($format->template)['file_type'];

        return [
            'name' => sprintf('bank-file-%s-%s-%s.%s', Str::slug($format->name), $run->pay_date->toDateString(), $createdAt->format('His'), $extension),
            'content' => $content,
            'mime' => $extension === 'csv' ? 'text/csv; charset=UTF-8' : 'text/plain; charset=US-ASCII',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function genericCsv(BankFormat $format, PayrollRun $run, array $payments): string
    {
        $reference = $this->reference($format->setting('payment_reference'), $run);

        return $this->csv(
            ['Payee name', 'Bank name', 'Account number', 'Sort / routing code', 'Amount', 'Currency', 'Payment date', 'Reference', 'Employee number'],
            array_map(fn (array $payment): array => [
                $payment['account']->account_holder, $payment['account']->bank_name, $this->compact($payment['account']->account_number), $this->compact($payment['account']->bank_code),
                Money::format($payment['amount_cents']), $run->currency, $run->pay_date->toDateString(), $reference, $payment['employee_number'],
            ], $payments),
        );
    }

    /**
     * Bacs Standard 18 fields; transaction code 99 is a credit to the destination account.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function bacsCsv(BankFormat $format, PayrollRun $run, array $payments): string
    {
        $reference = $this->ascii($this->reference($format->setting('payment_reference'), $run), 18);

        return $this->csv(
            ['Destination sort code', 'Destination account number', 'Destination account name', 'Transaction code', 'Amount', 'Reference', 'Originator sort code', 'Originator account number', 'Originator name', 'Service user number', 'Processing date'],
            array_map(fn (array $payment): array => [
                $this->compact($payment['account']->bank_code), $this->compact($payment['account']->account_number), $this->ascii($payment['account']->account_holder, 18), '99',
                Money::format($payment['amount_cents']), $reference, $format->setting('originator_sort_code'), $format->setting('originator_account_number'),
                $this->ascii($format->setting('originator_name'), 18), $format->setting('service_user_number'), $run->pay_date->format('d/m/Y'),
            ], $payments),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function ibftCsv(BankFormat $format, PayrollRun $run, array $payments): string
    {
        return $this->csv(
            ['Beneficiary name', 'Beneficiary IBAN', 'Beneficiary bank', 'Amount', 'Currency', 'Value date', 'Purpose', 'Debit account IBAN', 'Remitter name', 'Reference'],
            array_map(function (array $payment) use ($format, $run): array {
                $iban = $this->compact($payment['account']->bank_code) ?: $this->compact($payment['account']->account_number);

                return [
                    $payment['account']->account_holder, $iban, $payment['account']->bank_name, Money::format($payment['amount_cents']), $run->currency,
                    $run->pay_date->toDateString(), $format->setting('payment_purpose') ?? 'Salary', $format->setting('debit_iban'), $format->setting('company_name'),
                    trim('SAL '.$run->period_end->format('M Y').' '.($payment['employee_number'] ?? '')),
                ];
            }, $payments),
        );
    }

    /**
     * A NACHA ACH file: one PPD batch of checking-account credits (transaction code 22), 94-character records,
     * padded with lines of 9s to a multiple of ten records.
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function nacha(BankFormat $format, PayrollRun $run, array $payments, CarbonImmutable $createdAt): string
    {
        $odfi = (string) $format->setting('odfi_routing_number');
        $companyId = (string) $format->setting('company_id');
        $companyName = (string) $format->setting('company_name');
        $odfi8 = substr($odfi, 0, 8);
        $records = [];

        $records[] = '1'.'01'.' '.$odfi.$this->field($companyId, 10).$createdAt->format('ymd').$createdAt->format('Hi').'A'.'094'.'10'.'1'
            .$this->field($format->setting('odfi_name'), 23).$this->field($companyName, 23).$this->field('', 8);
        $records[] = '5'.'220'.$this->field($companyName, 16).$this->field('', 20).$this->field($companyId, 10).'PPD'
            .$this->field($format->setting('entry_description') ?? 'PAYROLL', 10).$run->period_end->format('ymd').$run->pay_date->format('ymd')
            .'   '.'1'.$odfi8.'0000001';

        $hash = 0;
        $total = 0;
        foreach (array_values($payments) as $index => $payment) {
            $routing = $this->compact($payment['account']->bank_code);
            $hash += (int) substr($routing, 0, 8);
            $total += $payment['amount_cents'];
            $records[] = '6'.'22'.$routing.$this->field($this->compact($payment['account']->account_number), 17)
                .$this->number($payment['amount_cents'], 10).$this->field($payment['employee_number'] ?? '', 15)
                .$this->field($payment['account']->account_holder, 22).'  '.'0'.$odfi8.$this->number($index + 1, 7);
        }

        $hash10 = $this->number($hash % 10_000_000_000, 10);
        $count = count($payments);
        $records[] = '8'.'220'.$this->number($count, 6).$hash10.$this->number(0, 12).$this->number($total, 12)
            .$this->field($companyId, 10).$this->field('', 19).$this->field('', 6).$odfi8.'0000001';

        $blocks = (int) ceil((count($records) + 1) / 10);
        $records[] = '9'.$this->number(1, 6).$this->number($blocks, 6).$this->number($count, 8).$hash10
            .$this->number(0, 12).$this->number($total, 12).$this->field('', 39);
        while (count($records) % 10 !== 0) {
            $records[] = str_repeat('9', 94);
        }

        return implode("\r\n", $records)."\r\n";
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (mixed $value): string => $this->safeCell((string) ($value ?? '')), $row), ',', '"', '');
        }
        rewind($out);
        $content = (string) stream_get_contents($out);
        fclose($out);

        return $content;
    }

    /**
     * Stops spreadsheet programs from treating a name as a formula.
     */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }

    private function reference(?string $setting, PayrollRun $run): string
    {
        return $setting ?? 'SALARY '.strtoupper($run->period_end->format('M Y'));
    }

    private function compact(?string $value): string
    {
        return preg_replace('/[\s-]+/', '', strtoupper((string) $value));
    }

    /**
     * Upper-case ASCII, cut to the length banks accept.
     */
    private function ascii(?string $value, int $length): string
    {
        $value = strtoupper(Str::ascii((string) $value));

        return substr(trim((string) preg_replace('/[^A-Z0-9 .&\/-]/', '', $value)), 0, $length);
    }

    /**
     * A fixed-width, left-justified, space-padded field.
     */
    private function field(?string $value, int $length): string
    {
        return str_pad($this->ascii($value, $length), $length);
    }

    private function number(int $value, int $length): string
    {
        return str_pad((string) $value, $length, '0', STR_PAD_LEFT);
    }
}
