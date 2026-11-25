<?php

namespace App\Models;

use App\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Role extends Model
{
    use BelongsToCompany;

    /** is_system / is_protected / key are never mass assignable. */
    protected $fillable = ['name', 'name_ar', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_protected' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role) {
            if (empty($role->uuid)) {
                $role->uuid = Str::uuid()->toString();
            }
        });
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return \DB::table('role_permissions')->where('role_id', $this->id)->orderBy('permission')->pluck('permission')->all();
    }
}
