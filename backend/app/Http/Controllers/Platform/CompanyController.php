<?php

namespace App\Http\Controllers\Platform;

use App\Actions\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M.Y HRMS operations: customer company accounts.
 */
class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->toString();

        $companies = Company::query()
            ->withCount(['memberships as member_count' => fn ($query) => $query->where('status', CompanyUser::STATUS_ACTIVE)])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25);

        return response()->json($companies);
    }

    public function store(CompanyAccountRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $data = $request->validated();

        $result = $createCompany->handle($data['company'], $data['admin'], $request->user());
        $email = $result['admin']->email;

        return response()->json([
            'company' => $result['company'],
            'admin' => ['id' => $result['admin']->id, 'name' => $result['admin']->name, 'email' => $email],
            'message' => $result['admin_is_new']
                ? "Company created. {$email} has been emailed a link to set their password."
                : "Company created and added to the existing account of {$email}.",
        ], 201);
    }
}
