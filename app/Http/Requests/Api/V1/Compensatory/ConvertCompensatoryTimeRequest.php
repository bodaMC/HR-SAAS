<?php

namespace App\Http\Requests\Api\V1\Compensatory;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertCompensatoryTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            // year defaults to current year if not provided
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            // custom_hours_threshold: optional override; defaults to schedule daily hours (9h)
            'custom_hours_threshold' => ['nullable', 'numeric', 'min:1', 'max:24'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
