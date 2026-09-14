<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff identity and authorization — NOT staff business information.
 *
 * A User is "who may sign in and what may they do". An Employee is "who works
 * here and what do they do for customers" (see the employees migration). They
 * are deliberately separate tables, because the real cases do not line up: an
 * owner who performs no services, a stylist with no system access, a manager
 * who is both.
 *
 * Lives in the TENANT database, so a staff account is structurally scoped to
 * one center. There is no cross-center account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');

            // Either may identify the user; at least one is required, enforced
            // in the application. Phone is primary in the target market.
            $table->string('email')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();

            // Nullable: a staff account can be created before its owner sets a
            // password, which is how a manager adds someone without ever
            // knowing their credentials (see staff_activation_tokens).
            $table->string('password')->nullable();

            $table->boolean('is_active')->default(true)->index();

            // Marks the account as the center's owner for PROTECTION — it may
            // not be deactivated or stripped of the owner role by others. It is
            // NOT an authorization bypass: the Owner role holds explicit grants
            // for every permission (docs/DECISIONS.md ADR-029).
            $table->boolean('is_owner')->default(false);

            // Branch scope. True = every branch, including ones created later.
            // False = exactly the branches listed in user_branches.
            $table->boolean('all_branches')->default(false);

            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
