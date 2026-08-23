<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Verify User exists
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Verify User is active
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your user account is inactive.',
                'code' => 'USER_INACTIVE',
            ], Response::HTTP_FORBIDDEN);
        }

        // 3. Resolve User's Company
        $company = $user->company;

        if (! $company) {
            return response()->json([
                'message' => 'User is not associated with any company.',
                'code' => 'NO_COMPANY',
            ], Response::HTTP_FORBIDDEN);
        }

        // 4. Verify Company is active
        if (! $company->is_active) {
            return response()->json([
                'message' => 'Your company is inactive.',
                'code' => 'COMPANY_INACTIVE',
            ], Response::HTTP_FORBIDDEN);
        }

        // 5. Set TenantContext
        app(TenantContext::class)->set($company);

        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        app(TenantContext::class)->clear();
    }
}
