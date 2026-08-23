<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Register a new Company and its initial Owner user atomically.
     */
    public function registerCompany(RegisterCompanyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = $validated['company_slug'] ?? Str::slug($validated['company_name']);

        if (empty($slug)) {
            $slug = 'company-'.Str::lower(Str::random(6));
        }

        if (Company::where('slug', $slug)->exists()) {
            $slug = $slug.'-'.Str::lower(Str::random(4));
        }

        return DB::transaction(function () use ($validated, $slug) {
            // 1. Create the Company
            $company = Company::create([
                'name' => $validated['company_name'],
                'slug' => $slug,
                'is_active' => true,
            ]);

            // 2. Establish TenantContext before creating User so BelongsToCompany hook succeeds
            app(TenantContext::class)->set($company);

            // 3. Create the Owner User with forced server-side attributes
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'role' => UserRole::Owner,
                'is_active' => true,
            ]);

            // 4. Issue Sanctum token with configurable expiration
            $expiration = config('sanctum.expiration');
            $expiresAt = $expiration ? now()->addMinutes((int) $expiration) : null;
            $token = $user->createToken('orbit-auth-token', ['*'], $expiresAt)->plainTextToken;

            return response()->json([
                'message' => 'Company and owner registered successfully.',
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user->load('company')),
                'company' => new CompanyResource($company),
            ], Response::HTTP_CREATED);
        });
    }

    /**
     * Authenticate an existing user and issue a Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::withoutGlobalScopes()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your user account is inactive.',
                'code' => 'USER_INACTIVE',
            ], Response::HTTP_FORBIDDEN);
        }

        $company = $user->company;

        if (! $company || ! $company->is_active) {
            return response()->json([
                'message' => 'Your company is inactive.',
                'code' => 'COMPANY_INACTIVE',
            ], Response::HTTP_FORBIDDEN);
        }

        $deviceName = $validated['device_name'] ?? 'orbit-auth-token';
        $expiration = config('sanctum.expiration');
        $expiresAt = $expiration ? now()->addMinutes((int) $expiration) : null;
        $token = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;

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

        return response()->json([
            'user' => new UserResource($user->load('company')),
            'company' => new CompanyResource($user->company),
        ], Response::HTTP_OK);
    }
}
