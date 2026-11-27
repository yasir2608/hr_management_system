<?php

namespace App\Http\Controllers\Reports;

use App\Dashboards\HrReports;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR reports for people with HR report access. Each report echoes its date and filters so it can be understood later.
 */
class HrReportController extends Controller
{
    public function __construct(private readonly HrReports $reports) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'types' => collect(HrReports::TYPES)->map(fn (array $type, string $key): array => ['key' => $key, ...$type])->values(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'countries' => Employee::query()->whereNotNull('work_country')->distinct()->orderBy('work_country')->pluck('work_country')
                ->map(fn (string $code): array => ['code' => $code, 'name' => config("countries.{$code}.name") ?? $code])->values(),
        ]);
    }

    public function show(Request $request, string $type, CurrentCompany $company): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'ulid', Rule::exists(Department::class, 'id')->where('company_id', $company->id())],
            'location_id' => ['nullable', 'ulid', Rule::exists(Location::class, 'id')->where('company_id', $company->id())],
            'country' => ['nullable', 'string', 'size:2'],
            'as_of' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::today();
        $filters = [
            'department_id' => $data['department_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'country' => isset($data['country']) ? strtoupper($data['country']) : null,
            'as_of' => isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : $today,
            'from' => isset($data['from']) ? CarbonImmutable::parse($data['from']) : $today->subDays(30),
            'to' => isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today,
        ];
        abort_if($filters['from']->diffInDays($filters['to']) > 366 * 3, 422, 'Choose a period of three years or less.');

        $report = $this->reports->build($type, $filters);

        return response()->json([
            ...$report,
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'department' => $filters['department_id'] ? Department::query()->find($filters['department_id'])?->name : null,
                'location' => $filters['location_id'] ? Location::query()->find($filters['location_id'])?->name : null,
                'country' => $filters['country'] ? (config("countries.{$filters['country']}.name") ?? $filters['country']) : null,
                'as_of' => in_array('as_of', $report['uses'], true) ? $filters['as_of']->toDateString() : null,
                'from' => in_array('range', $report['uses'], true) ? $filters['from']->toDateString() : null,
                'to' => in_array('range', $report['uses'], true) ? $filters['to']->toDateString() : null,
            ],
        ]);
    }
}
