<?php

namespace App\Http\Requests\Api\V1\Employees;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Models\Employee;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $this->user()->can('update', $employee);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $tenantId = app(TenantContext::class)->id();

        // Prevent setting self or existing direct/indirect subordinates as manager to avoid cycles
        $invalidManagerIds = $employee instanceof Employee
            ? array_merge([$employee->id], $employee->getAllSubordinateIds())
            : [];

        return [
            'employee_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('employees', 'employee_number')
                    ->where('company_id', $tenantId)
                    ->ignore($employee),
            ],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('employees', 'email')
                    ->where('company_id', $tenantId)
                    ->ignore($employee),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'national_id' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'job_title_id' => [
                'nullable',
                'integer',
                Rule::exists('job_titles', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::notIn($invalidManagerIds),
                Rule::exists('employees', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
                Rule::unique('employees', 'user_id')
                    ->where('company_id', $tenantId)
                    ->ignore($employee),
            ],
        ];
    }
}
