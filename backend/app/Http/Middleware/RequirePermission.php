<?php

namespace App\Http\Middleware;

use App\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:a,b` lets the request through when the user holds any of the listed permissions
 * in the current company. Must run after the `company` middleware.
 */
class RequirePermission
{
    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! $this->access->canAny(...$permissions)) {
            return response()->json([
                'message' => 'You do not have permission to do this. Ask your company administrator if you need access.',
                'code' => 'PERMISSION_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
