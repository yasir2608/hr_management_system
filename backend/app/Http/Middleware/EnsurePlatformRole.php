<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits a route to M.Y HRMS staff (users.platform_role), e.g. `platform:ops`.
 */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->platform_role;

        if ($role === null || ($roles !== [] && ! in_array($role, $roles, true))) {
            return response()->json([
                'message' => 'This area is only for M.Y HRMS operations staff.',
                'code' => 'PLATFORM_ACCESS_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
