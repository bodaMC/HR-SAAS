<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Projects\AssignProjectEmployeesRequest;
use App\Http\Requests\Api\V1\Projects\StoreProjectRequest;
use App\Http\Requests\Api\V1\Projects\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Project::with('employees');

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return ProjectResource::collection($query->get());
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = DB::transaction(function () use ($request) {
            $proj = Project::create([
                'name' => $request->string('name'),
                'code' => $request->input('code'),
                'description' => $request->input('description'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'is_active' => $request->boolean('is_active', true),
            ]);

            if ($request->has('assigned_employees')) {
                $syncData = [];
                foreach ($request->input('assigned_employees') as $item) {
                    $syncData[$item['employee_id']] = [
                        'company_id' => $proj->company_id,
                        'is_project_engineer' => (bool) ($item['is_project_engineer'] ?? false),
                        'assigned_at' => now(),
                    ];
                }
                $proj->employees()->sync($syncData);
            }

            $this->auditLogger->log('create_project', $proj, null, $proj->toArray());

            return $proj;
        });

        return (new ProjectResource($project->load('employees')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->load('employees'));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $old = $project->toArray();
        $project->update($request->validated());
        $this->auditLogger->log('update_project', $project, $old, $project->fresh()->toArray());

        return new ProjectResource($project->load('employees'));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $old = $project->toArray();
        $project->delete();
        $this->auditLogger->log('delete_project', $project, $old, null);

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }

    public function assignEmployees(AssignProjectEmployeesRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $syncData = [];
        foreach ($request->input('assignments') as $item) {
            $syncData[$item['employee_id']] = [
                'company_id' => $project->company_id,
                'is_project_engineer' => (bool) ($item['is_project_engineer'] ?? false),
                'assigned_at' => $item['assigned_at'] ?? now(),
            ];
        }

        $project->employees()->syncWithoutDetaching($syncData);
        $this->auditLogger->log('assign_project_employees', $project, null, $syncData);

        return response()->json([
            'message' => 'Employees assigned to project successfully.',
            'project' => new ProjectResource($project->fresh('employees')),
        ]);
    }
}
