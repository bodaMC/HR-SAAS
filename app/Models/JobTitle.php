<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Database\Factories\JobTitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class JobTitle extends Model
{
    /** @use HasFactory<JobTitleFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * Note: uuid and company_id are excluded — uuid is auto-generated in booted(),
     * and company_id is strictly derived from TenantContext via BelongsToCompany.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Auto-generate a UUID for new JobTitle records.
     */
    protected static function booted(): void
    {
        static::creating(function (JobTitle $jobTitle) {
            if (empty($jobTitle->uuid)) {
                $jobTitle->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the company that owns the job title.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employees associated with the job title.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
