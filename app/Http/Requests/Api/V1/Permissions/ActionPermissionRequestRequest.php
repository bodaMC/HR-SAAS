<?php

namespace App\Http\Requests\Api\V1\Permissions;

use Illuminate\Foundation\Http\FormRequest;

class ActionPermissionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
