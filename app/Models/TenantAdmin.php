<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAdmin extends Model
{
    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password'];

    protected $casts = ['is_active' => 'boolean'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
