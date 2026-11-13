<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProvisionTenant extends Command
{
    protected $signature = 'tenant:provision {name} {slug} {email} {admin_name}';

    protected $description = 'Create a tenant and its first owner account.';

    public function handle(): int
    {
        $name = trim($this->argument('name'));
        $slug = Str::slug($this->argument('slug'));
        $email = strtolower(trim($this->argument('email')));

        if ($slug === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid tenant slug and email address.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant && TenantAdmin::where('tenant_id', $tenant->id)->where('email', $email)->exists()) {
            $this->error('This email already has an admin account in that organization.');

            return self::FAILURE;
        }

        $password = $this->secret('Initial admin password (minimum 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('The password must contain at least 12 characters.');

            return self::FAILURE;
        }

        $tenant ??= Tenant::create(['name' => $name, 'slug' => $slug, 'primary_color' => '#2563EB', 'is_active' => true]);
        TenantAdmin::create([
            'tenant_id' => $tenant->id,
            'name' => trim($this->argument('admin_name')),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->info("Owner account is ready for '{$tenant->name}' (slug: {$slug}).");

        return self::SUCCESS;
    }
}
