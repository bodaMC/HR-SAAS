<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Access\StoreRoleRequest;
use App\Http\Requests\Api\V1\Access\UpdateRoleRequest;
use App\Models\Role;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rbac\RbacService;
use App\Rbac\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(private RbacService $rbac) {}

    private function need(Request $request, string ...$any): void
    {
        foreach ($any as $p) {
            if (PermissionResolver::has($request->user(), $p)) {
                return;
            }
        }
        throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
    }

    /** @return array<string,mixed> */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'uuid' => $role->uuid,
            'key' => $role->key,
            'name' => $role->name,
            'name_ar' => $role->name_ar,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_protected' => $role->is_protected,
            'permissions' => $role->permissionCodes(),
            'users_count' => DB::table('user_roles')->where('role_id', $role->id)->count(),
        ];
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->need($request, 'permissions.view', 'roles.view');
        $grantable = app(RbacService::class)->grantable($request->user());

        return response()->json(['data' => [
            'groups' => collect(Registry::groups())->map(fn ($codes, $group) => [
                'key' => $group,
                'permissions' => collect($codes)->map(fn ($c) => ['code' => $c, 'grantable_by_you' => in_array($c, $grantable, true)])->all(),
            ])->values()->all(),
            'protected' => Registry::protectedCodes(),
            'reserved' => Registry::reserved(),
            'your_permissions' => PermissionResolver::for($request->user()),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'roles.view');
        $roles = Role::query()->orderByDesc('is_system')->orderBy('name')->get();

        return response()->json(['data' => $roles->map(fn (Role $r) => $this->present($r))->all()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->need($request, 'roles.view');

        return response()->json(['data' => $this->present($role)])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->rbac->createRole($request->user(), $request->validated());

        return response()->json(['data' => $this->present($role)], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->rbac->updateRole($request->user(), $role, $request->validated());

        return response()->json(['data' => $this->present($role->refresh())]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->rbac->deleteRole($request->user(), $role);

        return response()->json(['message' => 'Role deleted.']);
    }
}
