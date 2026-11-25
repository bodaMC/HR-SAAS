<?php

namespace App\Http\Requests\Api\V1\Access;

use App\Rbac\PermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PermissionResolver::has($this->user(), 'roles.assign');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array', 'max:50'],
            'role_ids.*' => ['integer', 'distinct'],
        ];
    }
}
