<?php

namespace App\Http\Middleware;

use App\Access\Access;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\SupportAccessGrant;
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

        if ($membership === null && $user->platform_role !== null && Str::isUlid($companyId)) {
            $grant = SupportAccessGrant::activeFor($user->getAuthIdentifier(), $companyId);

            if ($grant !== null) {
                return $this->asSupport($request, $grant, $next);
            }
        }

        if ($membership === null || $membership->status === CompanyUser::STATUS_REMOVED) {
            $this->recordBlockedAttempt($request, $companyId);

            return $this->deny('You do not have access to this company.', 'COMPANY_ACCESS_DENIED', 403);
        }

        if (! $membership->isActive()) {
            return $this->deny('Your access to this company is suspended. Contact your company administrator.', 'MEMBERSHIP_SUSPENDED', 403);
        }

        if (! $membership->company->isActive()) {
            return $this->deny('This company account is suspended. Contact M.Y HRMS support.', 'COMPANY_SUSPENDED', 403);
        }

        // Permissions are read fresh for every request, so role changes apply immediately.
        app(Access::class)->forget();

        return $this->currentCompany->runAs($membership->company, fn (): Response => $next($request));
    }

    /**
     * Support staff with an active grant: read-only, and every request is recorded in the
     * company's activity history so the company can see what support looked at.
     */
    private function asSupport(Request $request, SupportAccessGrant $grant, Closure $next): Response
    {
        if (! $grant->company->isActive() && ! $request->isMethodSafe()) {
            return $this->deny('This company account is suspended.', 'COMPANY_SUSPENDED', 403);
        }

        if (! $request->isMethodSafe()) {
            return $this->deny('Support access is read-only. Ask the company to make this change.', 'SUPPORT_READ_ONLY', 403);
        }

        app(Access::class)->forget();

        return $this->currentCompany->runAs($grant->company, function () use ($request, $grant, $next): Response {
            ActivityLog::query()->create([
                'company_id' => $grant->company_id,
                'actor_user_id' => $grant->user_id,
                'action' => 'support.viewed',
                'subject_type' => 'support_access_grant',
                'subject_id' => $grant->id,
                'description' => 'M.Y HRMS support viewed '.$request->path(),
                'properties' => ['path' => $request->path(), 'reason' => $grant->reason],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            return $next($request);
        });
    }

    /**
     * Keeps a security record of an attempt to open a company the user has no access to.
     * When the id belongs to a real company, that company can see the attempt in its history.
     */
    private function recordBlockedAttempt(Request $request, string $companyId): void
    {
        $exists = Str::isUlid($companyId) && Company::query()->whereKey($companyId)->exists();

        ActivityLog::query()->create([
            'company_id' => $exists ? $companyId : null,
            'actor_user_id' => $request->user()->getAuthIdentifier(),
            'action' => 'security.company_access_blocked',
            'description' => 'Blocked an attempt to open a company without access',
            'properties' => ['requested_company_id' => Str::limit($companyId, 40, ''), 'path' => $request->path()],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }

    private function deny(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
