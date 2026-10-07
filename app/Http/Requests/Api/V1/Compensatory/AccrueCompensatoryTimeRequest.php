<?php

namespace App\Http\Requests\Api\V1\Compensatory;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccrueCompensatoryTimeRequest extends FormRequest
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
            'hours' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'overtime_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
