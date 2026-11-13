<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminApiToken extends Model
{
    protected $fillable = ['tenant_admin_id', 'token_hash', 'expires_at', 'last_used_at'];

    protected $casts = ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(TenantAdmin::class, 'tenant_admin_id');
    }
}
