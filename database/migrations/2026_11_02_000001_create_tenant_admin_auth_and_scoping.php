<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('primary_color', 7)->default('#2563EB');
            $table->string('logo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $legacyTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Lingofy',
            'slug' => 'lingofy',
            'primary_color' => '#2563EB',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        DB::table('students')->whereNull('tenant_id')->update(['tenant_id' => $legacyTenantId]);
        DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $legacyTenantId]);
        DB::table('courses')->whereNull('tenant_id')->update(['tenant_id' => $legacyTenantId]);
        DB::table('quizzes')->whereNull('tenant_id')->update(['tenant_id' => $legacyTenantId]);

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_email_unique');
            $table->unique(['tenant_id', 'email'], 'students_tenant_email_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->index('tenant_id', 'courses_tenant_id_index');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->index('tenant_id', 'quizzes_tenant_id_index');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('tenant_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role', 32)->default('owner');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'email'], 'tenant_admins_tenant_email_unique');
        });

        Schema::create('admin_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_admin_id')->constrained('tenant_admins')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_api_tokens');
        Schema::dropIfExists('tenant_admins');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('courses_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('quizzes_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropUnique('students_tenant_email_unique');
            $table->dropColumn('tenant_id');
            $table->unique('email', 'students_email_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropUnique('users_tenant_email_unique');
            $table->dropColumn('tenant_id');
            $table->unique('email', 'users_email_unique');
        });

        Schema::dropIfExists('tenants');
    }
};
