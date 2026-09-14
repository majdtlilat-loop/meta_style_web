<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer CRM record. Meta Style's first real PII table.
 *
 * A CUSTOMER IS NOT AN ACCOUNT. There is no password column here, and there
 * never will be: authentication lives in `customer_accounts`, one row per
 * customer, created only if that customer wants a login. Merging the two would
 * make "guest" a second kind of table instead of the absence of a row, and it
 * would put a credential in the middle of a record that reception edits all day
 * (docs/13-ROADMAP.md Phase 5 §§2, 4).
 *
 * NO `branch_id`, deliberately and permanently. A customer belongs to the
 * center, not to one of its locations — the same person books at Karrada on
 * Monday and Mansour on Friday and must keep one profile. Bookings and sales
 * carry the branch; the customer does not (Phase 5 §20).
 *
 * PHONE IS THE IDENTITY. Stored twice: `phone` is E.164 and is what uniqueness
 * and lookup use, `phone_display` is what the person actually typed and is what
 * they are shown. Unique per tenant, which is per DATABASE here — there is no
 * global constraint and no cross-center directory (§1).
 *
 * Nothing about loyalty, packages, memberships, wallets, booking history or
 * financial totals. Those are later modules and read models; a column here
 * would be a number nobody keeps correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Not translatable: a person's name is written the way they write
            // it, not once per language.
            $table->string('name', 190);

            // Canonical E.164. Nullable because a walk-in placeholder is a real
            // operational case — reception starts a record before asking for a
            // number.
            $table->string('phone', 20)->nullable();
            $table->string('phone_display', 32)->nullable();

            $table->string('email', 190)->nullable();

            // Which language to speak to them in. Resolved through the tenant's
            // enabled locales at read time, so a center disabling Kurdish does
            // not strand a customer who chose it.
            $table->string('preferred_locale', 12)->nullable();

            // For a future birthday reward. A date, never a timestamp: a
            // birthday is the same day in every timezone.
            $table->date('date_of_birth')->nullable();

            // Where the record came from: staff | guest | self_registration |
            // import | booking. A small controlled catalog, not a
            // marketing attribution engine.
            $table->string('source', 32)->default('staff')->index();

            // Communication consent. The data foundation only — no delivery,
            // no campaigns. Later notification code must find consent already
            // stored rather than inventing its own (§13).
            $table->boolean('allow_operational_messages')->default(true);
            $table->boolean('marketing_opt_in')->default(false);

            // Consent without a timestamp is not evidence of consent.
            $table->timestamp('marketing_opt_in_at')->nullable();

            // Archive, never delete: bookings, invoices, reviews and payments
            // will reference this row, and history has to stay readable (§10).
            $table->timestamp('archived_at')->nullable()->index();

            $table->timestamps();

            // PER TENANT, because the table lives in the tenant's own database.
            // The same real person may exist independently in two centers and
            // neither may learn of the other.
            $table->unique('phone');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
