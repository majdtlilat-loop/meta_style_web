<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's optional login. Separate from the CRM record, and from staff.
 *
 * ONE ROW PER CUSTOMER, AT MOST. `customer_id` is unique, so the relationship
 * is genuinely one-to-one rather than one-to-one-by-convention. A customer with
 * no row here is a guest; that is the whole of the guest/registered
 * distinction, and it means a guest becoming registered is an INSERT here, not
 * a new customer (docs/13-ROADMAP.md Phase 5 §§5, 9).
 *
 * NOT a staff `User`. Different table, different guard, different provider, and
 * no roles or permissions at all — a customer is not a member of staff with
 * fewer boxes ticked. Sanctum compares a token's owner against its guard's
 * provider model, so a customer token presented to the staff guard is refused
 * and the reverse too.
 *
 * `phone_verified_at` IS NEVER SET BY PHASE 5 CODE. There is no SMS or WhatsApp
 * provider yet, and marking a number verified without one would be a lie the
 * rest of the system would then trust. The column exists so verification can
 * arrive without a migration, and an architecture test asserts nothing writes
 * to it (§8, ADR-040).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Bcrypt via the `hashed` cast. Nullable so an account can later be
            // created by an operator for a customer to activate, exactly as
            // staff accounts work.
            $table->string('password')->nullable();

            // Withdrawing access must be immediate and must not depend on a
            // token expiring.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->timestamps();

            // The one-to-one guarantee, enforced by the database rather than by
            // remembering to check.
            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
