<?php

namespace App\Http\Controllers\Activity;

use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CsvExport;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The company's activity history: who did what, to which record, and when. Read-only; entries cannot be changed or
 * removed. Sensitive values are masked when recorded; details of payroll and pay entries are shown only to people
 * with salary access.
 */
class ActivityHistoryController extends Controller
{
    /** Action prefix => area label. */
    public const AREAS = [
        'employee' => 'Employees', 'employee_import' => 'Employee imports', 'onboarding' => 'Onboarding',
        'user' => 'Users and invitations', 'role' => 'Roles and access', 'security' => 'Security', 'support' => 'Support access',
        'company' => 'Company setup', 'department' => 'Organisation', 'team' => 'Organisation', 'location' => 'Organisation',
        'leave' => 'Leave', 'attendance' => 'Attendance', 'approval' => 'Approvals',
        'payroll' => 'Payroll', 'contractor' => 'Contractors', 'report' => 'Reports and exports',
    ];

    /** Subject type => page path prefix, for linking to the affected record. */
    private const LINKS = ['employee' => '/employees/', 'payroll_run' => '/payroll/runs/', 'contractor' => '/contractors/', 'payslip' => '/payroll/payslips/'];

    public function __construct(private readonly CurrentCompany $company, private readonly SensitiveData $sensitive) {}

    public function index(Request $request): JsonResponse
    {
        $entries = $this->filtered($request)->with('actor:id,name')->paginate(50);
        $entries->getCollection()->transform(fn (ActivityLog $entry): array => $this->present($entry));

        $actors = ActivityLog::query()->where('company_id', $this->company->id())->whereNotNull('actor_user_id')->distinct()->pluck('actor_user_id');

        return response()->json([
            ...$entries->toArray(),
            'areas' => collect(self::AREAS)->unique()->map(fn (string $label): array => ['key' => $label, 'label' => $label])->values(),
            'actors' => User::query()->whereIn('id', $actors)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $entries = $this->filtered($request)->with('actor:id,name')->limit(10000)->get();
        $filters = $this->filterLabels($request);

        return (new CsvExport('Activity history'))
            ->filters($filters)
            ->notice('Entries cannot be changed or removed. Sensitive values are masked.')
            ->table(['When', 'Who', 'Area', 'Action', 'What happened', 'Record type', 'Record', 'IP address'], $entries->map(function (ActivityLog $entry): array {
                $row = $this->present($entry);

                return [$entry->created_at?->toDateTimeString(), $row['actor'] ?? 'System', $row['area'], $entry->action, $entry->description, $row['subject']['label'] ?? null, $entry->subject_id, $entry->ip_address];
            })->all())
            ->logged('report.exported', "Activity history exported ({$entries->count()} entries)", ['report' => 'activity_history', 'rows' => $entries->count()])
            ->download('activity-history-'.now()->toDateString());
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function filtered(Request $request): Builder
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', 'string', 'max:80'],
            'actor_user_id' => ['nullable', 'ulid'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $prefixes = isset($data['area']) ? array_keys(array_filter(self::AREAS, fn (string $label): bool => $label === $data['area'])) : [];

        return ActivityLog::query()->where('company_id', $this->company->id())
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn (Builder $inner) => $inner->where('description', 'like', $like)->orWhere('action', 'like', $like));
            })
            ->when(isset($data['area']), fn (Builder $query) => $query->where(function (Builder $inner) use ($prefixes): void {
                $inner->whereRaw('1 = 0');
                foreach ($prefixes as $prefix) {
                    $inner->orWhere('action', 'like', "{$prefix}.%");
                }
            }))
            ->when($data['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($data['actor_user_id'] ?? null, fn (Builder $query, string $actor) => $query->where('actor_user_id', $actor))
            ->when($data['subject_type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($data['subject_id'] ?? null, fn (Builder $query, string $id) => $query->where('subject_id', $id))
            ->when($data['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', "{$from} 00:00:00"))
            ->when($data['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', "{$to} 23:59:59.999999"))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string, string|null>
     */
    private function filterLabels(Request $request): array
    {
        return [
            'Search' => $request->string('search')->toString() ?: null,
            'Area' => $request->string('area')->toString() ?: null,
            'Action' => $request->string('action')->toString() ?: null,
            'Who' => $request->filled('actor_user_id') ? User::query()->find($request->string('actor_user_id')->toString())?->name : null,
            'Record' => $request->filled('subject_id') ? $request->string('subject_type')->toString().' '.$request->string('subject_id')->toString() : null,
            'From' => $request->string('from')->toString() ?: null,
            'To' => $request->string('to')->toString() ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ActivityLog $entry): array
    {
        $prefix = strstr($entry->action, '.', true) ?: $entry->action;
        $restricted = $this->isPayRelated($entry->action) && ! $this->sensitive->canSee(SensitiveData::SALARY);

        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'area' => self::AREAS[$prefix] ?? ucfirst(str_replace('_', ' ', $prefix)),
            'description' => $entry->description,
            'actor' => $entry->actor?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
            'ip_address' => $entry->ip_address,
            'subject' => $entry->subject_type === null ? null : [
                'type' => $entry->subject_type,
                'id' => $entry->subject_id,
                'label' => ucfirst(str_replace('_', ' ', $entry->subject_type)),
                'link' => isset(self::LINKS[$entry->subject_type]) && $entry->subject_id ? self::LINKS[$entry->subject_type].$entry->subject_id : null,
            ],
            'details' => $restricted ? null : ($entry->properties ?: null),
            'details_restricted' => $restricted && ! empty($entry->properties),
        ];
    }

    private function isPayRelated(string $action): bool
    {
        return str_starts_with($action, 'payroll.') || str_contains($action, 'payroll_details') || str_contains($action, 'compensation') || str_contains($action, 'payslip');
    }
}
