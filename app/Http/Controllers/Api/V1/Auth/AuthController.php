<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Accounts\AccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Rbac\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Authenticate an existing user and issue a Sanctum token.
     */
    public function login(LoginRequest $request, AccountService $accounts): JsonResponse
    {
        $validated = $request->validated();

        // Phase 3.3 rate limiting of failed sign-ins: per email+IP and per IP. Successful sign-ins clear the
        // per-email counter. The response is identical for unknown email and wrong password.
        $emailKey = 'login|'.mb_strtolower($validated['email']).'|'.$request->ip();
        $ipKey = 'login-ip|'.$request->ip();
        if (RateLimiter::tooManyAttempts($emailKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $wait = max(RateLimiter::availableIn($emailKey), RateLimiter::availableIn($ipKey));

            return response()->json(['message' => __('accounts.too_many_attempts'), 'code' => 'TOO_MANY_ATTEMPTS'], Response::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', (string) max(1, $wait));
        }

        $user = User::withoutGlobalScopes()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($emailKey, 60);
            RateLimiter::hit($ipKey, 60);
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }
        [$status, $locked, $token] = $accounts->authenticate($user, $validated['password'], $validated['device_name'] ?? 'orbit-auth-token');
        if ($status === 'failed') {
            // The credentials or the account changed between the first check and the locked re-check.
            RateLimiter::hit($emailKey, 60);
            RateLimiter::hit($ipKey, 60);
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }
        RateLimiter::clear($emailKey);
        if ($status === 'inactive') {
            return response()->json(['message' => 'Your user account is inactive.', 'code' => 'USER_INACTIVE'], Response::HTTP_FORBIDDEN);
        }
        if ($status === 'company_inactive') {
            return response()->json(['message' => 'Your company is inactive.', 'code' => 'COMPANY_INACTIVE'], Response::HTTP_FORBIDDEN);
        }
        $user = $locked;
        $company = $user->company;

        return response()->json([
            'message' => 'Authenticated successfully.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('company')),
            'company' => new CompanyResource($company),
        ], Response::HTTP_OK);
    }

    /**
     * Revoke the current authenticated access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ], Response::HTTP_OK);
    }

    /**
     * Return the authenticated user profile and their associated company.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->orderBy('r.name')->get(['r.id', 'r.key', 'r.name', 'r.name_ar', 'r.is_system'])
            ->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'name_ar' => $r->name_ar, 'is_system' => (bool) $r->is_system])->all();

        // Server-verified capabilities, recomputed on every request and never cached.
        return response()->json([
            'user' => new UserResource($user->load('company')),
            'company' => new CompanyResource($user->company),
            'access' => [
                'permissions' => PermissionResolver::for($user),
                'roles' => $roles,
                'is_platform_admin' => $user->isSuperAdmin(),
            ],
        ], Response::HTTP_OK)->header('Cache-Control', 'private, no-store');
    }
}
