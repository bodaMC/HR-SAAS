<?php

namespace App\Http\Requests\Api\V1\Access;

use App\Rbac\PermissionResolver;
use App\Rbac\Registry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PermissionResolver::has($this->user(), 'roles.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $role = $this->route('role');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('roles', 'name')->where('company_id', $tenant)->ignore($role?->id)],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Registry::assignable())],
        ];
    }
}
