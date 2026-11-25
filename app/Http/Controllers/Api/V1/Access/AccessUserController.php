<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Access\SyncUserRolesRequest;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rbac\RbacService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Minimal, tenant-scoped account directory for role assignment. Platform accounts are never listed. */
class AccessUserController extends Controller
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
    private function present(User $u): array
    {
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $u->id)->orderBy('r.name')->get(['r.id', 'r.key', 'r.name', 'r.name_ar', 'r.is_system'])
            ->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'name_ar' => $r->name_ar, 'is_system' => (bool) $r->is_system])->all();

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => (bool) $u->is_active, 'legacy_role' => $u->role?->value, 'roles' => $roles];
    }

    private function visible(): Builder
    {
        return User::query()->where(fn ($q) => $q->where('is_super_admin', false)->orWhereNull('is_super_admin'));
    }

    public function index(Request $request): JsonResponse
    {
        $this->need($request, 'roles.view', 'roles.assign');
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = $this->visible();
        if ($request->filled('search')) {
            $like = '%'.mb_strtolower(addcslashes(trim((string) $request->input('search')), '\\%_')).'%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$like]));
        }
        // Tenant-scoped (global scope) server-side search and pagination with an accurate total.
        $page = $q->orderBy('name')->orderBy('id')->paginate((int) $request->input('per_page', 25), ['*'], 'page', (int) $request->input('page', 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->present($u))->all(),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function permissions(Request $request, int $user): JsonResponse
    {
        $this->need($request, 'permissions.view', 'roles.view');
        $target = $this->visible()->findOrFail($user);

        return response()->json(['data' => $this->present($target) + ['effective_permissions' => PermissionResolver::for($target)]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function syncRoles(SyncUserRolesRequest $request, int $user): JsonResponse
    {
        $target = User::query()->find($user);
        if (! $target) {
            throw new RbacDenied(404, 'not_found', 'Not found.');
        }
        $this->rbac->syncUserRoles($request->user(), $target, $request->validated()['role_ids']);

        return response()->json(['data' => $this->present($target->refresh()) + ['effective_permissions' => PermissionResolver::for($target)]]);
    }
}
