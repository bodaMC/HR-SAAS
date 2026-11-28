<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AccountService;
use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Employees\IndexEmployeesRequest;
use App\Http\Requests\Api\V1\Employees\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Employees\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Company;
use App\Models\Employee;
use App\Organization\EmployeeEligibility;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function __construct(private AccountService $accounts, private AuditLogger $audit) {}

    /**
     * Display a listing of employees for the active company.
     */
    public function index(IndexEmployeesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::with(['department', 'jobTitle', 'manager']);

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('job_title_id')) {
            $query->where('job_title_id', $request->input('job_title_id'));
        }

        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->input('manager_id'));
        }

        // Phase 3.3: the directory defaults to CURRENT employees (not terminated). 'former' = terminated,
        // 'all' = both. An explicit employment_status filter applies on its own unless scope is also given.
        $scope = $request->input('scope');
        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->input('employment_status'));
        }
        if ($scope === 'former') {
            $query->where('employment_status', EmploymentStatus::Terminated->value);
        } elseif ($scope === 'current' || ($scope === null && ! $request->filled('employment_status'))) {
            $query->where('employment_status', '!=', EmploymentStatus::Terminated->value);
        }

        if ($request->filled('employment_type')) {
            $type = $request->input('employment_type');
            $type === 'none' ? $query->whereNull('employment_type') : $query->where('employment_type', $type);
        }

        if ($request->filled('is_trainee')) {
            $query->where('is_trainee', filter_var($request->input('is_trainee'), FILTER_VALIDATE_BOOLEAN));
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
        $data = $request->validated();
        if (array_key_exists('is_trainee', $data)) {
            $data['is_trainee'] = (bool) $data['is_trainee'];
        }
        $employee = DB::transaction(function () use ($data, $request) {
            Company::withoutGlobalScopes()->whereKey((int) request()->user()->company_id)->lockForUpdate()->first(); // tenant lock first: same order as every organization and lifecycle mutation
            $elig = app(EmployeeEligibility::class);
            $cid = (int) $request->user()->company_id;
            $elig->assertJobTitle($cid, $data['job_title_id'] ?? null, $data['department_id'] ?? null);
            $elig->assertManager($cid, null, $data['manager_id'] ?? null, $data['department_id'] ?? null);
            $employee = Employee::create($data);
            $this->audit->log('employee.created', $employee, null, ['employee_number' => $employee->employee_number, 'employment_status' => $employee->employment_status->value], $request->user());

            return $employee;
        });

        return (new EmployeeResource($employee->load(['department', 'jobTitle', 'manager'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load(['department', 'jobTitle', 'manager']));
    }

    /**
     * Update the specified employee in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $data = $request->validated();
        if (array_key_exists('is_trainee', $data)) {
            $data['is_trainee'] = (bool) $data['is_trainee'];
        }
        // Status rules, fresh authorisation, protection and self-edit checks all run inside AccountService under the
        // tenant, employee and account locks (same order as every lifecycle mutation).
        $this->accounts->updateEmployee($request->user(), $employee->id, $data);
        $employee->refresh();

        return new EmployeeResource($employee->load(['department', 'jobTitle', 'manager']));
    }

    /**
     * Remove the specified employee from storage.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $this->accounts->deleteEmployee(request()->user(), $employee->id);

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ], Response::HTTP_OK);
    }

    /** Terminate employment: deactivates the linked ordinary account and revokes its sessions atomically. */
    public function terminate(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'termination_date' => ['nullable', 'date'],
        ]);
        $done = $this->accounts->terminate($request->user(), $employee->id, $data['reason'], $data['termination_date'] ?? null);

        return new EmployeeResource($done->load(['department', 'jobTitle', 'manager']));
    }

    /** Reactivate a former employee. The login stays inactive until explicitly reactivated. */
    public function reactivate(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $done = $this->accounts->reactivateEmployee($request->user(), $employee->id, $data['reason']);

        return new EmployeeResource($done->load(['department', 'jobTitle', 'manager']));
    }
}
