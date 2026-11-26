<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JobTitles\StoreJobTitleRequest;
use App\Http\Requests\Api\V1\JobTitles\UpdateJobTitleRequest;
use App\Http\Resources\Api\V1\JobTitleResource;
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
            $m = JobTitle::create($request->validated());
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
            $old = $jobTitle->only(array_keys($request->validated()));
            $jobTitle->update($request->validated());
            $this->audit('job_title.updated', $jobTitle, $old, $jobTitle->only(array_keys($request->validated())));
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
