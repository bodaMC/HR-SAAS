<?php

namespace App\Http\Requests\Api\V1\Leave;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveAllocationRequest extends FormRequest
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
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')
                    ->where('company_id', $tenantId),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'total_allocated_days' => ['required', 'numeric', 'min:0'],
            'carried_over_days' => ['nullable', 'numeric', 'min:0'],
            'used_days' => ['nullable', 'numeric', 'min:0'],
            'pending_days' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
