<?php

namespace App\Http\Requests\Api\V1\Schedules;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $timeFields = ['work_start_time', 'work_end_time', 'implicit_approval_time'];

        foreach ($timeFields as $field) {
            $value = $this->input($field);
            if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
                $this->merge([$field => $value.':00']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'standard_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'work_start_time' => ['required', 'date_format:H:i:s'],
            'work_end_time' => ['required', 'date_format:H:i:s'],
            'is_friday_weekend' => ['nullable', 'boolean'],
            'is_saturday_weekend' => ['nullable', 'boolean'],
            'implicit_approval_time' => ['nullable', 'date_format:H:i:s'],
        ];
    }
}
