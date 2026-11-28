<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Phase 3.4 assignment of an employee to a position (primary | acting | deputy), with inclusive effective dates. */
class OrgPositionAssignment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'ended_at' => 'datetime', 'voided_at' => 'datetime', 'is_primary_placement' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrgPositionAssignment $a) {
            $a->uuid = $a->uuid ?: Str::uuid()->toString();
        });
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(OrgPosition::class, 'position_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
