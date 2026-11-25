<?php

namespace App\Http\Requests\Api\V1\Access;

use App\Rbac\PermissionResolver;
use App\Rbac\Registry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PermissionResolver::has($this->user(), 'roles.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('company_id', $tenant)],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Registry::assignable())],
        ];
    }
}
