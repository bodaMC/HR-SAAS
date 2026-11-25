<?php

namespace App\Http\Requests\Api\V1\Employees;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates directory filters. Malformed input is a 422 (never a 500 and
 * never a silent empty list). Authorization stays in the controller policy.
 */
class IndexEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Employee::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = ['nullable', 'integer', 'min:1', 'max:2147483647'];

        return [
            'department_id' => $id,
            'job_title_id' => $id,
            'manager_id' => $id,
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }
}
