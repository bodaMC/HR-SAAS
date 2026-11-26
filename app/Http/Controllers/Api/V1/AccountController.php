<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AccountService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase 3.3 employee login-account lifecycle. Authorization and protection live in AccountService. */
class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /** Coarse permission gate evaluated before validation so an unauthorized caller always gets 403, never a validation 422. Fine-grained and protection rules stay in AccountService. */
    private function gate(Request $request, string $permission): void
    {
        if (! PermissionResolver::has($request->user(), $permission)) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }

    private function respond(Request $request, int $employeeId, array $extra = [], int $status = 200): JsonResponse
    {
        $employee = Employee::query()->whereKey($employeeId)->firstOrFail();

        return response()->json(['data' => $this->accounts->state($request->user(), $employee)] + $extra, $status)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        if (! PermissionResolver::has($request->user(), 'accounts.view')) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }

        return $this->respond($request, $employee->id);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.create');
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['nullable', 'string', new StrongPassword],
            'status' => ['nullable', 'in:active,inactive'],
            'confirm_employee_uuid' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $out = $this->accounts->create($request->user(), $employee->id, $data);

        return $this->respond($request, $employee->id, ['temporary_password' => $out['temporary_password'], 'message' => 'Login account created.'], 201);
    }

    /** Minimal Link picker data: only accounts this actor may administer and that are not linked yet. */
    public function linkCandidates(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.link');
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->accounts->linkCandidates($request->user(), $request->query('q'))])->header('Cache-Control', 'no-store');
    }

    public function link(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.link');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'confirm_employee_uuid' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $this->accounts->link($request->user(), $employee->id, (int) $data['user_id'], $data['confirm_employee_uuid'], $data['reason']);

        return $this->respond($request, $employee->id, ['message' => 'Account linked.']);
    }

    public function unlink(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.link');
        $data = $request->validate(['confirm_employee_uuid' => ['required', 'string'], 'reason' => ['required', 'string', 'max:500']]);
        $this->accounts->unlink($request->user(), $employee->id, $data['confirm_employee_uuid'], $data['reason']);

        return $this->respond($request, $employee->id, ['message' => 'Account unlinked.']);
    }

    public function resetPassword(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.reset_password');
        $data = $request->validate([
            'confirm_employee_uuid' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
            'password' => ['nullable', 'string', new StrongPassword],
        ]);
        $out = $this->accounts->resetPassword($request->user(), $employee->id, $data['confirm_employee_uuid'], $data['reason'], $data['password'] ?? null);

        return $this->respond($request, $employee->id, ['temporary_password' => $out['temporary_password'], 'message' => 'Password reset. The employee must change it at next sign-in.']);
    }

    public function deactivate(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.deactivate');
        $data = $request->validate(['confirm_employee_uuid' => ['required', 'string'], 'reason' => ['required', 'string', 'max:500']]);
        $this->accounts->deactivate($request->user(), $employee->id, $data['confirm_employee_uuid'], $data['reason']);

        return $this->respond($request, $employee->id, ['message' => 'Account deactivated and sessions revoked.']);
    }

    public function reactivate(Request $request, Employee $employee): JsonResponse
    {
        $this->gate($request, 'accounts.activate');
        $data = $request->validate(['confirm_employee_uuid' => ['required', 'string'], 'reason' => ['required', 'string', 'max:500']]);
        $this->accounts->reactivate($request->user(), $employee->id, $data['confirm_employee_uuid'], $data['reason']);

        return $this->respond($request, $employee->id, ['message' => 'Account reactivated.']);
    }
}
