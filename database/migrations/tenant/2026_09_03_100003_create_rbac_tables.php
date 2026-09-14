<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control, tenant-scoped.
 *
 * The PERMISSION CATALOG is not here. Permission codes are defined in
 * application code (App\Kernel\Authorization\Permission) and only the
 * assignments are data. Seeding a catalog into every tenant database would
 * guarantee drift: one tenant missing a row silently denies access, and adding
 * a permission would become a data migration across every database
 * (docs/06-AUTH-ROLES-PERMISSIONS.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Stable machine key: owner, manager, host, cashier, employee, or a
            // generated key for a center's own custom role.
            $table->string('key', 64)->unique();
            $table->json('name');

            // System roles are re-synced from code on provisioning and cannot
            // be deleted; custom roles belong to the center.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            // A code from the catalog in code. Not a foreign key on purpose —
            // see the class note above.
            $table->string('permission', 96);
            $table->timestamps();

            $table->unique(['role_id', 'permission']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('user_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
