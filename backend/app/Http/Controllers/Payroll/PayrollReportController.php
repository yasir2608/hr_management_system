<?php

namespace App\Http\Controllers\Payroll;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\CompanyPayrollCountry;
use App\Models\PayrollGroup;
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

    public function __construct(private readonly PayrollReports $reports, private readonly SensitiveData $sensitive) {}

    public function options(): JsonResponse
    {
        $countries = CompanyPayrollCountry::codes();

        return response()->json([
            'countries' => collect($countries)->map(fn (string $code): array => ['country_code' => $code, 'country_name' => config("countries.{$code}.name"), 'currency' => config("countries.{$code}.currency")])->all(),
            'groups' => PayrollGroup::query()->orderBy('name')->get(['id', 'name', 'country_code']),
            'types' => collect(PayrollReports::TYPES)->map(fn (array $type, string $key): array => ['key' => $key, ...$type])->values(),
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
        $country = config("countries.{$filters['country']}.name");
        $group = $filters['group_id'] ? PayrollGroup::query()->find($filters['group_id'])?->name : null;
        $scope = "{$country}".($group ? " – {$group}" : '').", pay dates {$filters['from']->toDateString()} to {$filters['to']->toDateString()}, {$report['currency']}";

        Activity::record('payroll.report_downloaded', "{$report['name']} downloaded ({$scope})", null, [
            'report' => $type, 'country_code' => $filters['country'], 'payroll_group_id' => $filters['group_id'],
            'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString(), 'rows' => count($report['rows']), 'employees' => $report['employee_count'],
        ]);

        $name = sprintf('%s-%s-%s-to-%s.csv', Str::slug($report['name']), strtolower($filters['country']), $filters['from']->toDateString(), $filters['to']->toDateString());

        return response()->streamDownload(function () use ($report, $scope): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $put = fn (array $row) => fputcsv($out, array_map(fn (mixed $value): string => self::safeCell((string) ($value ?? '')), $row), ',', '"', '');
            $put([$report['name']]);
            $put([$scope]);
            $put([self::NOTICE]);
            foreach ($report['warnings'] as $warning) {
                $put(["Note: {$warning}"]);
            }
            $put([]);
            $put(array_column($report['columns'], 'label'));
            foreach ($report['rows'] as $row) {
                $put(array_map(fn (array $column): mixed => $row[$column['key']] ?? '', $report['columns']));
            }
            $put(array_map(fn (array $column, int $index): string => $index === 0 ? 'Total' : (string) ($report['totals'][$column['key']] ?? ''), $report['columns'], array_keys($report['columns'])));
            if ($report['excluded'] !== []) {
                $put([]);
                $put(['Not included', 'Reason']);
                foreach ($report['excluded'] as $excluded) {
                    $put([$excluded['name'], $excluded['reason']]);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
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
