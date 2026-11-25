<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Employees\IndexEmployeesRequest;
use App\Http\Requests\Api\V1\Employees\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Employees\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees for the active company.
     */
    public function index(IndexEmployeesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::with(['department', 'jobTitle', 'manager', 'user']);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('job_title_id')) {
            $query->where('job_title_id', $request->input('job_title_id'));
        }

        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->input('manager_id'));
        }

        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->input('employment_status'));
        }

        if ($request->filled('search')) {
            // Escape LIKE wildcards so "%" and "_" match literally, and also
            // match the full name ("first last") typed as one phrase.
            $term = trim((string) $request->input('search'));
            $like = '%'.mb_strtolower(addcslashes($term, '\\%_')).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw("LOWER(first_name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(employee_number) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        $employees = $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get();

        return EmployeeResource::collection($employees);
    }

    /**
     * Store a newly created employee in storage.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = DB::transaction(fn () => Employee::create($request->validated()));

        return (new EmployeeResource($employee->load(['department', 'jobTitle', 'manager', 'user'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load(['department', 'jobTitle', 'manager', 'user']));
    }

    /**
     * Update the specified employee in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        DB::transaction(fn () => $employee->update($request->validated()));

        return new EmployeeResource($employee->load(['department', 'jobTitle', 'manager', 'user']));
    }

    /**
     * Remove the specified employee from storage.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ], Response::HTTP_OK);
    }
}
