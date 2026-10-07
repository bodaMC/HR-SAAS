<?php

namespace App\Http\Requests\Api\V1\Projects;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignProjectEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'assignments.*.is_project_engineer' => ['nullable', 'boolean'],
            'assignments.*.assigned_at' => ['nullable', 'date'],
        ];
    }
}
