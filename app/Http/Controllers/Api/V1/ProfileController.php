<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AccountService;
use App\Accounts\AvatarService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Phase 3.3 self-service: change own password, view My Profile, edit ONLY phone, manage own picture. */
class ProfileController extends Controller
{
    /** The explicit allowlist of self-service editable employee fields. */
    public const EDITABLE = ['phone'];

    public function __construct(private AccountService $accounts, private AvatarService $avatars, private AuditLogger $audit) {}

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', new StrongPassword],
            'password_confirmation' => ['required', 'string'],
        ]);
        if (! hash_equals($data['password'], $data['password_confirmation'])) {
            throw ValidationException::withMessages(['password_confirmation' => [__('accounts.password_confirmation')]]);
        }
        $token = DB::transaction(function () use ($request, $data) {
            // Same protocol as sign-in and the lifecycle mutations: tenant (shared) then the account row; the password,
            // activity and trashed state are decided on the locked row, never on the pre-lock request user.
            $this->lockOwn($request->user());
            $user = User::withoutGlobalScopes()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_active || $user->trashed()) {
                throw new RbacDenied(403, 'forbidden', 'Your user account is inactive.');
            }
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => [__('accounts.current_password_wrong')]]);
            }
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => [__('accounts.password_same')]]);
            }
            $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now()])->save();
            // Every previously issued token dies (including the current one); a fresh session is issued.
            $user->tokens()->delete();
            $this->audit->log('account.password_changed', $user, null, ['sessions_revoked' => true, 'self_service' => true], $user, null, AuditVisibility::RESTRICTED);

            return $this->accounts->issueToken($user);
        });

        return response()->json([
            'message' => 'Password changed.',
            'token' => $token,
            'token_type' => 'Bearer',
        ])->header('Cache-Control', 'no-store');
    }

    /** Tenant shared lock: waits for an in-flight lifecycle mutation, does not block other self-service calls. */
    private function lockOwn(User $user): void
    {
        if ($user->company_id !== null) {
            Company::withoutGlobalScopes()->whereKey($user->company_id)->sharedLock()->first();
        }
    }

    /**
     * Locked, fresh view of the caller's own employee row and account, in the lifecycle lock order
     * (tenant shared, employee, account). Refuses when the account was deactivated or unlinked meanwhile.
     */
    private function lockedSelf(User $user): Employee
    {
        $this->lockOwn($user);
        $fresh = Employee::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $account = User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();
        if (! $account || ! $account->is_active || $account->trashed()) {
            throw new RbacDenied(403, 'forbidden', 'Your user account is inactive.');
        }
        if (! $fresh) {
            throw new RbacDenied(404, 'no_employee_profile', 'No employee profile is linked to this account.');
        }

        return $fresh;
    }

    private function employeeOf(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    /** @return array<string,mixed> */
    private function present(User $user, ?Employee $e): array
    {
        $base = [
            'user' => new UserResource($user->load('company')),
            'company' => new CompanyResource($user->company),
            'has_employee' => $e !== null,
            'editable_fields' => self::EDITABLE,
        ];
        if (! $e) {
            return $base + ['employee' => null];
        }
        $e->loadMissing(['department', 'jobTitle']);

        return $base + ['employee' => [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'department' => $e->department?->name,
            'job_title' => $e->jobTitle?->name,
            'employment_type' => $e->employment_type,
            'is_trainee' => (bool) $e->is_trainee,
            'hire_date' => $e->hire_date?->format('Y-m-d'),
            'employment_status' => $e->employment_status->value,
            'work_email' => $e->email,
            'phone' => $e->phone,
            'has_picture' => $e->avatar_path !== null,
            'picture_version' => $e->avatar_updated_at?->timestamp,
        ]];
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->present($user, $this->employeeOf($user))])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $extra = array_diff(array_keys($request->all()), self::EDITABLE);
        if ($extra) {
            throw ValidationException::withMessages([array_values($extra)[0] => [__('accounts.profile_field_not_allowed')]]);
        }
        $data = $request->validate(['phone' => ['required_with:phone', 'nullable', 'string', 'max:50', 'regex:/^[0-9+()\\-\\s]{3,50}$/']]);
        $employee = $this->employeeOf($user);
        if (! $employee) {
            throw new RbacDenied(404, 'no_employee_profile', 'No employee profile is linked to this account.');
        }
        DB::transaction(function () use ($user, $data) {
            $fresh = $this->lockedSelf($user);
            $before = ['phone_changed' => true];
            $fresh->forceFill(['phone' => $data['phone'] ?? null])->save();
            User::withoutGlobalScopes()->whereKey($user->id)->update(['phone' => $data['phone'] ?? null]);
            $this->audit->log('employee.profile_updated', $fresh, null, $before + ['fields' => ['phone']], $user);
        });

        return response()->json(['data' => $this->present($user->refresh(), $this->employeeOf($user))]);
    }

    public function uploadPicture(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->employeeOf($user);
        if (! $employee) {
            throw new RbacDenied(404, 'no_employee_profile', 'No employee profile is linked to this account.');
        }
        $request->validate(['picture' => ['required', 'file']]);
        $new = $this->avatars->store($employee, $request->file('picture'));
        try {
            $old = DB::transaction(function () use ($new, $user) {
                $fresh = $this->lockedSelf($user);
                $old = $fresh->avatar_path;
                $fresh->forceFill(['avatar_path' => $new, 'avatar_updated_at' => now()])->save();
                $this->audit->log('employee.avatar_changed', $fresh, null, ['action' => 'uploaded'], $user);

                return $old;
            });
        } catch (\Throwable $e) {
            $this->avatars->delete($new);
            throw $e;
        }
        $this->avatars->delete($old);

        return response()->json(['data' => $this->present($user, $this->employeeOf($user))]);
    }

    public function removePicture(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->employeeOf($user);
        if (! $employee) {
            throw new RbacDenied(404, 'no_employee_profile', 'No employee profile is linked to this account.');
        }
        $old = DB::transaction(function () use ($user) {
            $fresh = $this->lockedSelf($user);
            $old = $fresh->avatar_path;
            $fresh->forceFill(['avatar_path' => null, 'avatar_updated_at' => now()])->save();
            $this->audit->log('employee.avatar_changed', $fresh, null, ['action' => 'removed'], $user);

            return $old;
        });
        $this->avatars->delete($old);

        return response()->json(['data' => $this->present($user, $this->employeeOf($user))]);
    }

    private function send(?string $file): BinaryFileResponse
    {
        if (! $file) {
            throw new RbacDenied(404, 'not_found', 'Not found.');
        }

        return response()->file($file, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="picture.jpg"',
        ]);
    }

    public function ownPicture(Request $request): BinaryFileResponse
    {
        $employee = $this->employeeOf($request->user());

        return $this->send($employee ? $this->avatars->stream($employee) : null);
    }

    /** Another employee's picture: employees.view holders (same tenant, tenant scope applies) or the owner. */
    public function employeePicture(Request $request, int $employee): BinaryFileResponse
    {
        $e = Employee::query()->whereKey($employee)->first();
        $user = $request->user();
        if (! $e || (! PermissionResolver::has($user, 'employees.view') && $e->user_id !== $user->id)) {
            throw new RbacDenied(404, 'not_found', 'Not found.');
        }

        return $this->send($this->avatars->stream($e));
    }
}
