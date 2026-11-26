<?php

namespace App\Http\Middleware;

use App\Models\CompanyUser;
use App\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the company the request acts for, from the `X-Company-Id` header.
 *
 * The signed-in user must hold an active membership of an active company. An unknown company and
 * a company the user does not belong to get the same response, so ids cannot be probed.
 */
class ResolveCompany
{
    public const HEADER = 'X-Company-Id';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny('You need to sign in first.', 'UNAUTHENTICATED', 401);
        }

        $companyId = $request->header(self::HEADER);

        if (! is_string($companyId) || $companyId === '') {
            return $this->deny('Choose a company to continue.', 'COMPANY_REQUIRED', 400);
        }

        $membership = Str::isUlid($companyId)
            ? CompanyUser::query()
                ->with('company')
                ->where('company_id', $companyId)
                ->where('user_id', $user->getAuthIdentifier())
                ->first()
            : null;

        if ($membership === null || $membership->status === CompanyUser::STATUS_REMOVED) {
            return $this->deny('You do not have access to this company.', 'COMPANY_ACCESS_DENIED', 403);
        }

        if (! $membership->isActive()) {
            return $this->deny('Your access to this company is suspended. Contact your company administrator.', 'MEMBERSHIP_SUSPENDED', 403);
        }

        if (! $membership->company->isActive()) {
            return $this->deny('This company account is suspended. Contact M.Y HRMS support.', 'COMPANY_SUSPENDED', 403);
        }

        return $this->currentCompany->runAs($membership->company, fn (): Response => $next($request));
    }

    private function deny(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
