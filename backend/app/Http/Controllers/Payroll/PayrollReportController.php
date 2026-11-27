<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Models\PayrollGroup;
use App\Payroll\Reports\CountryFilingSummary;
use App\Payroll\Reports\PayrollReports;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll reports from approved payroll runs: pick a country, payroll group, pay-date range and report, check the
 * preview (totals, employees, warnings, exclusions), then download a CSV. Downloads are recorded.
 */
class PayrollReportController extends Controller
{
    public const NOTICE = 'Built from approved payroll runs. This report is for review and records; it is not a government submission.';

    public function __construct(private readonly PayrollReports $reports, private readonly CountryFilingSummary $filing, private readonly SensitiveData $sensitive) {}

    public function filingSummary(Request $request): JsonResponse
    {
        return response()->json([...$this->filing->build($this->filters($request), $this->sensitive->canSee(SensitiveData::TAX_ID)), 'notice' => self::NOTICE]);
    }

    public function downloadFilingSummary(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $summary = $this->filing->build($filters, $this->sensitive->canSee(SensitiveData::TAX_ID));
        $scope = $this->scope($filters, $summary['currency']);

        Activity::record('payroll.report_downloaded', "{$summary['name']} downloaded ({$scope})", null, [
            'report' => 'filing_summary', 'country_code' => $filters['country'], 'payroll_group_id' => $filters['group_id'],
            'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString(), 'employees' => $summary['employee_count'],
            'tax_ids_included' => ! $summary['tax_ids_restricted'],
        ]);

        $name = sprintf('filing-summary-%s-%s-to-%s.csv', strtolower($filters['country']), $filters['from']->toDateString(), $filters['to']->toDateString());

        return $this->csv($name, function (callable $put) use ($summary, $scope): void {
            $put([$summary['name']]);
            $put([$scope]);
            $put([self::NOTICE.' These are not the official forms or files.']);
            foreach ($summary['warnings'] as $warning) {
                $put(["Note: {$warning}"]);
            }
            foreach ($summary['sections'] as $section) {
                $put([]);
                $put([$section['title']]);
                $this->table($put, $section['columns'], $section['rows'], $section['totals']);
            }
            $this->exclusions($put, $summary['excluded']);
        });
    }

    public function options(): JsonResponse
    {
        $countries = CompanyPayrollCountry::codes();

        return response()->json([
            'countries' => collect($countries)->map(fn (string $code): array => ['country_code' => $code, 'country_name' => config("countries.{$code}.name"), 'currency' => config("countries.{$code}.currency")])->all(),
            'groups' => PayrollGroup::query()->orderBy('name')->get(['id', 'name', 'country_code']),
            'types' => collect(PayrollReports::TYPES)->map(fn (array $type, string $key): array => ['key' => $key, ...$type])->values(),
            'filing_summaries' => collect($countries)->mapWithKeys(fn (string $code): array => [$code => CountryFilingSummary::TITLES[$code] ?? null])->filter(),
            'notice' => self::NOTICE,
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        return response()->json([...$this->reports->build($type, $this->filters($request)), 'notice' => self::NOTICE]);
    }

    public function download(Request $request, string $type): StreamedResponse
    {
        $filters = $this->filters($request);
        $report = $this->reports->build($type, $filters);
        $scope = $this->scope($filters, $report['currency']);

        Activity::record('payroll.report_downloaded', "{$report['name']} downloaded ({$scope})", null, [
            'report' => $type, 'country_code' => $filters['country'], 'payroll_group_id' => $filters['group_id'],
            'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString(), 'rows' => count($report['rows']), 'employees' => $report['employee_count'],
        ]);

        $name = sprintf('%s-%s-%s-to-%s.csv', Str::slug($report['name']), strtolower($filters['country']), $filters['from']->toDateString(), $filters['to']->toDateString());

        return $this->csv($name, function (callable $put) use ($report, $scope): void {
            $put([$report['name']]);
            $put([$scope]);
            $put([self::NOTICE]);
            foreach ($report['warnings'] as $warning) {
                $put(["Note: {$warning}"]);
            }
            $put([]);
            $this->table($put, $report['columns'], $report['rows'], $report['totals']);
            $this->exclusions($put, $report['excluded']);
        });
    }

    /**
     * @param  array{country: string, group_id: ?string, from: CarbonImmutable, to: CarbonImmutable}  $filters
     */
    private function scope(array $filters, string $currency): string
    {
        $country = config("countries.{$filters['country']}.name");
        $group = $filters['group_id'] ? PayrollGroup::query()->find($filters['group_id'])?->name : null;

        return "{$country}".($group ? " – {$group}" : '').", pay dates {$filters['from']->toDateString()} to {$filters['to']->toDateString()}, {$currency}";
    }

    /**
     * @param  callable(callable(list<mixed>): void): void  $write
     */
    private function csv(string $name, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write(fn (array $row) => fputcsv($out, array_map(fn (mixed $value): string => self::safeCell((string) ($value ?? '')), $row), ',', '"', ''));
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Header, rows and a totals row.
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     */
    private function table(callable $put, array $columns, array $rows, array $totals): void
    {
        $put(array_column($columns, 'label'));
        foreach ($rows as $row) {
            $put(array_map(fn (array $column): mixed => $row[$column['key']] ?? '', $columns));
        }
        $put(array_map(fn (array $column, int $index): string => $index === 0 ? 'Total' : (string) ($totals[$column['key']] ?? ''), $columns, array_keys($columns)));
    }

    /**
     * @param  list<array{name: string, reason: string}>  $excluded
     */
    private function exclusions(callable $put, array $excluded): void
    {
        if ($excluded === []) {
            return;
        }
        $put([]);
        $put(['Not included', 'Reason']);
        foreach ($excluded as $row) {
            $put([$row['name'], $row['reason']]);
        }
    }

    /**
     * @return array{country: string, group_id: ?string, from: CarbonImmutable, to: CarbonImmutable}
     */
    private function filters(Request $request): array
    {
        abort_unless($this->sensitive->canSee(SensitiveData::SALARY), 403, 'You need salary access to see payroll reports.');
        $latest = rescue(fn (): string => CarbonImmutable::parse((string) $request->input('from'))->addYears(3)->toDateString(), null, false);

        $data = $request->validate([
            'country' => ['required', Rule::in(CompanyPayrollCountry::codes())],
            'group_id' => ['nullable', 'ulid', Rule::exists(PayrollGroup::class, 'id')->where('country_code', (string) $request->input('country'))->where('company_id', app(CurrentCompany::class)->id())],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', ...($latest === null ? [] : ['before_or_equal:'.$latest])],
        ], [
            'country.in' => 'Choose one of the company’s payroll countries.',
            'group_id.exists' => 'Choose a payroll group in this country.',
            'to.before_or_equal' => 'Choose a range of three years or less.',
        ]);

        return ['country' => $data['country'], 'group_id' => $data['group_id'] ?? null, 'from' => CarbonImmutable::parse($data['from']), 'to' => CarbonImmutable::parse($data['to'])];
    }

    /**
     * Stops spreadsheet programs from treating a value as a formula.
     */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }
}
