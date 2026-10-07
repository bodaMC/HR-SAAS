<?php

namespace App\Http\Requests\Api\V1\Projects;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $projectId = $this->route('project')?->id ?? $this->route('project');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where('company_id', $tenantId)
                    ->ignore($projectId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('projects', 'code')
                    ->where('company_id', $tenantId)
                    ->ignore($projectId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
