<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Phase 3.4 organizational position. Exists independently of its incumbent; never carries authorization. */
class OrgPosition extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = ['archived_at' => 'datetime', 'removed_at' => 'datetime', 'display_order' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (OrgPosition $p) {
            $p->uuid = $p->uuid ?: Str::uuid()->toString();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrgPosition::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrgPosition::class, 'parent_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrgPositionAssignment::class, 'position_id');
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** UAT-02: a tombstoned position. Hidden from the chart, lists and selectors and never assignable; history and audit stay. */
    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }
}
