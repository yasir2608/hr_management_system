<?php

namespace App\Http\Controllers;

use App\Access\Access;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace search. Each kind of result is only searched when the user may see it, and
 * employees are limited to the user's scope, so search never reveals protected records,
 * not even how many exist.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, Access $access, CurrentCompany $currentCompany): JsonResponse
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $results = [];

        $scope = $access->scopeFor('employees.view');
        $self = $access->employee();

        $employees = Employee::query()
            ->where(fn ($query) => $query->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('preferred_name', 'like', $like)->orWhere('employee_number', 'like', $like))
            ->where(function ($query) use ($scope, $self): void {
                $scope->apply($query);

                if ($self !== null) {
                    $query->orWhere('employees.id', $self->id);
                }
            })
            ->limit(8)
            ->get();

        foreach ($employees as $employee) {
            $results[] = [
                'type' => 'employee',
                'id' => $employee->id,
                'title' => $employee->fullName(),
                'subtitle' => $employee->current_job_title,
                'href' => $self?->id === $employee->id && ! $scope->covers($employee) ? '/me' : "/employees/{$employee->id}",
            ];
        }

        if ($access->canAny('users.view', 'users.manage')) {
            CompanyUser::query()->where('company_id', $currentCompany->id())->where('status', '!=', CompanyUser::STATUS_REMOVED)
                ->whereHas('user', fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->with('user:id,name,email')->limit(5)->get()
                ->each(function (CompanyUser $membership) use (&$results): void {
                    $results[] = ['type' => 'user', 'id' => $membership->id, 'title' => $membership->user->name, 'subtitle' => $membership->user->email, 'href' => '/settings/users'];
                });
        }

        return response()->json(['data' => $results]);
    }
}
