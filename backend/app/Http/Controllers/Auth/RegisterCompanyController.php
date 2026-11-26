<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyAccountRequest;
use Illuminate\Http\JsonResponse;

class RegisterCompanyController extends Controller
{
    /**
     * Self-service sign-up: the owner creates the company and becomes its primary administrator.
     */
    public function store(CompanyAccountRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $data = $request->validated();

        $result = $createCompany->handle($data['company'], $data['admin']);

        return LoginController::completeSignIn($request, $result['admin'])->setStatusCode(201);
    }
}
