<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;

class TenantContext
{
    /**
     * The current tenant model.
     */
    protected ?Model $tenant = null;

    /**
     * Set the current tenant.
     */
    public function set(?Model $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Get the current tenant.
     */
    public function get(): ?Model
    {
        return $this->tenant;
    }

    /**
     * Get the current tenant ID.
     */
    public function id(): ?int
    {
        return $this->tenant ? $this->tenant->getKey() : null;
    }

    /**
     * Check if a tenant is currently resolved.
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Clear the current tenant.
     */
    public function clear(): void
    {
        $this->tenant = null;
    }
}
