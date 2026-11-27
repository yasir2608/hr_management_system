<?php

namespace App\Http\Controllers\Platform;

use App\Actions\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PlatformActivity;
use App\Models\SupportAccessGrant;
use App\Support\PlatformStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * M.Y HRMS operations: customer company accounts.
 */
class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([Company::STATUS_ACTIVE, Company::STATUS_SUSPENDED])]]);
        $search = $request->string('search')->toString();

        $companies = Company::query()
            ->withCount(['memberships as member_count' => fn ($query) => $query->where('status', CompanyUser::STATUS_ACTIVE)])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(25);

        $ids = $companies->getCollection()->pluck('id')->all();
        $employees = PlatformStats::perCompany('employees', $ids, fn ($query) => $query->where('current_status', '!=', 'ended'));
        $lastActivity = DB::table('activity_log')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, max(created_at) as last_at')->pluck('last_at', 'company_id');

        $companies->getCollection()->transform(fn (Company $company): array => [
            ...$company->only(['id', 'name', 'country_code', 'status', 'member_count', 'created_at']),
            'employee_count' => $employees[$company->id] ?? 0,
            'last_activity_at' => $lastActivity[$company->id] ?? null,
        ]);

        return response()->json($companies);
    }

    public function show(Company $company): JsonResponse
    {
        $members = CompanyUser::query()->with('user:id,name,email,status,last_login_at')->where('company_id', $company->id)
            ->orderByRaw("case status when 'active' then 0 when 'suspended' then 1 else 2 end")->orderBy('joined_at')->get();
        $roles = DB::table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->where('role_assignments.company_id', $company->id)->whereNull('role_assignments.revoked_at')
            ->get(['role_assignments.company_user_id', 'roles.name'])->groupBy('company_user_id');

        return response()->json([
            'company' => $company->only(['id', 'name', 'legal_name', 'country_code', 'contact_email', 'phone', 'city', 'status', 'require_mfa', 'created_at']),
            'suspension' => $company->status === Company::STATUS_SUSPENDED ? [
                'at' => $company->suspended_at, 'reason' => $company->suspension_reason,
                'by' => $company->suspended_by === null ? null : DB::table('users')->where('id', $company->suspended_by)->value('name'),
            ] : null,
            'usage' => [
                'active_members' => $members->where('status', CompanyUser::STATUS_ACTIVE)->count(),
                'employees' => DB::table('employees')->where('company_id', $company->id)->where('current_status', '!=', 'ended')->count(),
                'contractors' => DB::table('contractors')->where('company_id', $company->id)->where('status', 'active')->count(),
                'leave_requests_30d' => DB::table('leave_requests')->where('company_id', $company->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'clock_ins_30d' => DB::table('attendance_records')->where('company_id', $company->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'last_activity_at' => DB::table('activity_log')->where('company_id', $company->id)->max('created_at'),
                'last_sign_in_at' => $members->max(fn (CompanyUser $member) => $member->user?->last_login_at),
            ],
            'members' => $members->map(fn (CompanyUser $member): array => [
                'id' => $member->id, 'status' => $member->status, 'joined_at' => $member->joined_at,
                'user' => $member->user?->only(['id', 'name', 'email', 'status', 'last_login_at']),
                'roles' => ($roles[$member->id] ?? collect())->pluck('name')->unique()->values(),
            ]),
            'support_access' => SupportAccessGrant::query()->with('user:id,name')->where('company_id', $company->id)->latest()->limit(10)->get()
                ->map(fn (SupportAccessGrant $grant): array => [
                    ...$grant->only(['id', 'reason', 'ticket_reference', 'expires_at', 'revoked_at', 'created_at']), 'staff' => $grant->user?->name, 'active' => $grant->isActive(),
                ]),
            'staff_activity' => PlatformActivity::query()->with('actor:id,name')->where('company_id', $company->id)->latest('created_at')->limit(20)->get()
                ->map(fn (PlatformActivity $entry): array => [...$entry->only(['id', 'action', 'description', 'created_at']), 'actor' => $entry->actor?->name]),
        ]);
    }

    public function store(CompanyAccountRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $data = $request->validated();

        $result = $createCompany->handle($data['company'], $data['admin'], $request->user());
        $email = $result['admin']->email;

        PlatformActivity::record('company.created', "Company {$result['company']->name} created with {$email} as administrator", $result['company']->id, $result['admin']->id);

        return response()->json([
            'company' => $result['company'],
            'admin' => ['id' => $result['admin']->id, 'name' => $result['admin']->name, 'email' => $email],
            'message' => $result['admin_is_new']
                ? "Company created. {$email} has been emailed a link to set their password."
                : "Company created and added to the existing account of {$email}.",
        ], 201);
    }
}
