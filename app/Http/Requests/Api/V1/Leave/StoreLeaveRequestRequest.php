<?php

namespace App\Http\Requests\Api\V1\Leave;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
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
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')
                    ->where('company_id', $tenantId)
                    ->where('is_active', true),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('company_id', $tenantId)
                    ->where('is_active', true),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'attachment_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
