<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('platform_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->string('permission', 96);
            $table->timestamps();
            $table->unique(['role_id', 'permission']);
        });

        Schema::connection($this->connection)->create('platform_user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::connection($this->connection)->create('platform_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_password_reset_tokens');
        Schema::connection($this->connection)->dropIfExists('platform_user_roles');
        Schema::connection($this->connection)->dropIfExists('platform_role_permissions');
        Schema::connection($this->connection)->dropIfExists('platform_roles');
        Schema::connection($this->connection)->dropIfExists('platform_users');
    }
};
