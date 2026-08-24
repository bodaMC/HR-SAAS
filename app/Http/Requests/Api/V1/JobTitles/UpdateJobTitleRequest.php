<?php

namespace App\Http\Requests\Api\V1\JobTitles;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobTitleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $jobTitle = $this->route('job_title') ?? $this->route('jobTitle');

        return $this->user()->can('update', $jobTitle);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $jobTitle = $this->route('job_title') ?? $this->route('jobTitle');
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('job_titles', 'name')
                    ->where('company_id', $tenantId)
                    ->ignore($jobTitle),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
