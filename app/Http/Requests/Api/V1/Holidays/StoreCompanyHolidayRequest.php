<?php

namespace App\Http\Requests\Api\V1\Holidays;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => [
                'required',
                'date',
                Rule::unique('company_holidays', 'start_date')
                    ->where('company_id', $tenantId)
                    ->where('name', $this->input('name')),
            ],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
