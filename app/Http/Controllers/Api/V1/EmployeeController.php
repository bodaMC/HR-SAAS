<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Employees\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Employees\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees for the active company.
     */
    public function index(Request $request): AnonymousResourceCollection
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
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('employee_number', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $employees = $query->orderBy('first_name')->orderBy('last_name')->get();

        return EmployeeResource::collection($employees);
    }

    /**
     * Store a newly created employee in storage.
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($request->validated());

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
        $employee->update($request->validated());

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
