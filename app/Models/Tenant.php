<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'primary_color', 'logo_url', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function admins(): HasMany
    {
        return $this->hasMany(TenantAdmin::class);
    }
}
