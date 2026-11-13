<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminApiToken;
use App\Models\Tenant;
use App\Models\TenantAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_slug' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $tenant = Tenant::where('slug', strtolower($data['tenant_slug']))->where('is_active', true)->first();
        $admin = $tenant
            ? TenantAdmin::where('tenant_id', $tenant->id)->where('email', strtolower($data['email']))->where('is_active', true)->first()
            : null;

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['email' => ['The organization, email, or password is incorrect.']]);
        }

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(12);

        AdminApiToken::create([
            'tenant_admin_id' => $admin->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role],
            'tenant' => $this->tenantPayload($tenant),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('tenant_admin');
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role],
            'tenant' => $this->tenantPayload($tenant),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            AdminApiToken::where('token_hash', hash('sha256', $bearer))->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    private function tenantPayload(Tenant $tenant): array
    {
        return ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'primary_color' => $tenant->primary_color, 'logo_url' => $tenant->logo_url];
    }
}
