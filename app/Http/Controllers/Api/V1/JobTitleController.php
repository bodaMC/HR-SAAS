<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JobTitles\StoreJobTitleRequest;
use App\Http\Requests\Api\V1\JobTitles\UpdateJobTitleRequest;
use App\Http\Resources\Api\V1\JobTitleResource;
use App\Models\Company;
use App\Models\JobTitle;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class JobTitleController extends Controller
{
    private function audit(string $action, $model, ?array $old, ?array $new): void
    {
        app(AuditLogger::class)->log($action, $model, $old, $new);
    }

    private function deptIds(JobTitle $t): array
    {
        return DB::table('job_title_departments')->where('job_title_id', $t->id)->orderBy('department_id')->pluck('department_id')->map(fn ($x) => (int) $x)->all();
    }

    /** Selected departments are replaced as a set; an 'all' title keeps no rows. Runs inside the caller's transaction. */
    private function syncDepartments(JobTitle $t, array $v): void
    {
        if (! array_key_exists('department_availability', $v) && ! array_key_exists('department_ids', $v)) {
            return;
        }
        $mode = $t->department_availability;
        DB::table('job_title_departments')->where('job_title_id', $t->id)->delete();
        if ($mode === 'selected') {
            foreach (array_unique($v['department_ids'] ?? []) as $d) {
                DB::table('job_title_departments')->insert(['company_id' => $t->company_id, 'job_title_id' => $t->id, 'department_id' => $d, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Display a listing of job titles for the active company.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JobTitle::class);

        $query = JobTitle::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $jobTitles = $query->orderBy('name')->get();

        return JobTitleResource::collection($jobTitles);
    }

    /**
     * Store a newly created job title in storage.
     */
    public function store(StoreJobTitleRequest $request): JsonResponse
    {
        $jobTitle = DB::transaction(function () use ($request) {
            Company::withoutGlobalScopes()->whereKey((int) request()->user()->company_id)->lockForUpdate()->first(); // tenant lock first: same order as every organization and lifecycle mutation
            $v = $request->validated();
            $m = JobTitle::create(collect($v)->except('department_ids')->all());
            $this->syncDepartments($m, $v);
            $this->audit('job_title.created', $m, null, ['name' => $m->name]);

            return $m;
        });

        return (new JobTitleResource($jobTitle))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified job title.
     */
    public function show(JobTitle $jobTitle): JobTitleResource
    {
        $this->authorize('view', $jobTitle);

        return new JobTitleResource($jobTitle->load('employees'));
    }

    /**
     * Update the specified job title in storage.
     */
    public function update(UpdateJobTitleRequest $request, JobTitle $jobTitle): JobTitleResource
    {
        DB::transaction(function () use ($request, $jobTitle) {
            Company::withoutGlobalScopes()->whereKey((int) request()->user()->company_id)->lockForUpdate()->first(); // tenant lock first: same order as every organization and lifecycle mutation
            $v = $request->validated();
            JobTitle::query()->whereKey($jobTitle->id)->lockForUpdate()->first();
            $old = $jobTitle->only(array_keys($v)) + ['department_ids' => $this->deptIds($jobTitle)];
            $jobTitle->update(collect($v)->except('department_ids')->all());
            $this->syncDepartments($jobTitle, $v);
            $this->audit('job_title.updated', $jobTitle, $old, $jobTitle->only(array_keys($v)) + ['department_ids' => $this->deptIds($jobTitle)]);
        });

        return new JobTitleResource($jobTitle);
    }

    /**
     * Remove the specified job title from storage.
     */
    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('delete', $jobTitle);

        DB::transaction(function () use ($jobTitle) {
            $jobTitle->delete();
            $this->audit('job_title.deleted', $jobTitle, ['name' => $jobTitle->name], null);
        });

        return response()->json([
            'message' => 'Job title deleted successfully.',
        ], Response::HTTP_OK);
    }
}
