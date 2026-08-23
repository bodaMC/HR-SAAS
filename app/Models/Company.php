<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * Note: uuid is intentionally excluded — it is auto-generated in the creating hook below.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Auto-generate a UUID for new Company records.
     */
    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->uuid)) {
                $company->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the users associated with the company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
