<?php

namespace App\Tenancy\Traits;

use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCompany
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $tenantContext = app(TenantContext::class);

            if (! $tenantContext->hasTenant()) {
                throw new \RuntimeException('Tenant context is missing. Cannot create tenant-scoped model.');
            }

            $model->company_id = $tenantContext->id();
        });
    }

    /**
     * Prevent manual modification of company_id on existing models.
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'company_id' && $this->exists && $this->company_id != $value) {
            throw new \InvalidArgumentException('Tenant company_id cannot be mutated.');
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Custom Eloquent Builder to prevent query builder / bulk updates to company_id.
     */
    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                if (array_key_exists('company_id', $values) || array_key_exists($this->model->getTable().'.company_id', $values)) {
                    throw new \InvalidArgumentException('Tenant company_id cannot be mutated.');
                }

                return parent::update($values);
            }
        };
    }

    /**
     * Get the company that owns the model.
     */
    public function company()
    {
        $companyClass = 'App\\Models\\Company';
        if (class_exists($companyClass)) {
            return $this->belongsTo($companyClass, 'company_id');
        }

        return $this->belongsTo(Model::class, 'company_id');
    }
}
