<?php

namespace App\Http\Requests\Api\V1\Permissions;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionRequestRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['normal', 'deduction', 'compensatory'])],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i', 'date_format:H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i', 'date_format:H:i:s'],
            'duration_hours' => ['required', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
