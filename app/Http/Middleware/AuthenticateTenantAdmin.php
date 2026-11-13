<?php

namespace App\Http\Middleware;

use App\Models\AdminApiToken;
use Closure;
use Illuminate\Http\Request;

class AuthenticateTenantAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if (! $bearer || strlen($bearer) < 40) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = AdminApiToken::with(['admin.tenant'])
            ->where('token_hash', hash('sha256', $bearer))
            ->first();

        if (! $token || $token->expires_at->isPast() || ! $token->admin?->is_active || ! $token->admin?->tenant?->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('tenant_admin', $token->admin);
        $request->attributes->set('tenant', $token->admin->tenant);

        return $next($request);
    }
}
