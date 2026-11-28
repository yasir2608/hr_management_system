<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits a route to operations staff who have been given billing rights (`platform.billing`).
 */
class EnsureBillingManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->canManageBilling() !== true) {
            return response()->json([
                'message' => 'Only operations staff with billing rights can change customer billing.',
                'code' => 'BILLING_ACCESS_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
