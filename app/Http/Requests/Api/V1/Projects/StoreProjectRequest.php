<?php

namespace App\Http\Requests\Api\V1\Projects;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')->where('company_id', $tenantId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('projects', 'code')->where('company_id', $tenantId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['nullable', 'boolean'],
            'assigned_employees' => ['nullable', 'array'],
            'assigned_employees.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'assigned_employees.*.is_project_engineer' => ['nullable', 'boolean'],
        ];
    }
}
