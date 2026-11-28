<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Departments\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\Departments\UpdateDepartmentRequest;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Models\Department;
use App\Organization\OrgService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DepartmentController extends Controller
{
    private function audit(string $action, $model, ?array $old, ?array $new): void
    {
        app(AuditLogger::class)->log($action, $model, $old, $new);
    }

    /**
     * Display a listing of departments for the active company.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Department::class);

        $query = Department::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $departments = $query->orderBy('name')->get();

        return DepartmentResource::collection($departments);
    }

    /**
     * Store a newly created department in storage.
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = DB::transaction(function () use ($request) {
            $m = Department::create($request->validated());
            $this->audit('department.created', $m, null, ['name' => $m->name]);

            return $m;
        });

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): DepartmentResource
    {
        $this->authorize('view', $department);

        return new DepartmentResource($department->load('employees'));
    }

    /**
     * Update the specified department in storage.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        DB::transaction(function () use ($request, $department) {
            $old = $department->only(array_keys($request->validated()));
            $department->update($request->validated());
            $this->audit('department.updated', $department, $old, $department->only(array_keys($request->validated())));
        });

        return new DepartmentResource($department);
    }

    /**
     * Remove the specified department from storage.
     */
    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        DB::transaction(function () use ($department) {
            // Phase 3.4 F03: same tenant lock as organization writes; refuses while active positions use this department.
            app(OrgService::class)->assertDepartmentDeletable((int) $department->company_id, (int) $department->id);
            $department->delete();
            $this->audit('department.deleted', $department, ['name' => $department->name], null);
        });

        return response()->json([
            'message' => 'Department deleted successfully.',
        ], Response::HTTP_OK);
    }
}
