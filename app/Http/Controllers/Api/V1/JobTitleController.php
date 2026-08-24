<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JobTitles\StoreJobTitleRequest;
use App\Http\Requests\Api\V1\JobTitles\UpdateJobTitleRequest;
use App\Http\Resources\Api\V1\JobTitleResource;
use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class JobTitleController extends Controller
{
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
        $jobTitle = JobTitle::create($request->validated());

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
        $jobTitle->update($request->validated());

        return new JobTitleResource($jobTitle);
    }

    /**
     * Remove the specified job title from storage.
     */
    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        $this->authorize('delete', $jobTitle);

        $jobTitle->delete();

        return response()->json([
            'message' => 'Job title deleted successfully.',
        ], Response::HTTP_OK);
    }
}
