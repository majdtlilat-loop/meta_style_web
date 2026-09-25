<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's read model of every center's USER ACCOUNTS (owners, managers,
 * staff) — so the Super Admin can list and search them without opening every
 * tenant database on each page view. Projected from each tenant by
 * CenterUserDirectory; the tenant's own `users` table stays the truth.
 *
 * Never customers: a center's customers have no row here, and no customer
 * directory across centers exists (docs/13-ROADMAP.md Phase 5).
 *
 * `phone_e164` is the canonical number; `phone_national` (the digits after the
 * calling code) makes "7501234567" and "+9647501234567" find the same person
 * with a prefix search. Both are NULL for a legacy account without a phone —
 * shown as "Phone missing", never back-filled with an invented number.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('center_user_directory', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->uuid('user_uuid');
            $table->string('name', 190);
            $table->string('email', 190)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('phone_country', 2)->nullable();
            $table->string('phone_national', 20)->nullable();
            // owner | manager | employee — the directory's broad grouping.
            $table->string('kind', 16);
            $table->string('role_key', 64)->nullable();
            $table->json('roles')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('all_branches')->default(false);
            $table->dateTime('account_created_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('projected_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_uuid'], 'center_user_directory_tenant_user_unique');
            $table->index(['name'], 'center_user_directory_name_index');
            $table->index(['email'], 'center_user_directory_email_index');
            $table->index(['phone_e164'], 'center_user_directory_phone_index');
            $table->index(['phone_national'], 'center_user_directory_national_index');
            $table->index(['kind', 'is_active'], 'center_user_directory_kind_active_index');
            $table->index(['account_created_at'], 'center_user_directory_created_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('center_user_directory');
    }
};
