<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The right to review one completed visit, once.
 *
 * `service_journey_id` is UNIQUE, so the invariant "one visit, one invitation"
 * is the database's and not a query somebody has to remember to run. A repeated
 * `JourneyCompleted` — a retry, a reconciliation pass, two workers — collides
 * on the index and the second attempt quietly does nothing
 * (docs/22-REVIEWS.md §4).
 *
 * Only `token_hash` is stored. The plaintext lives in the URL the minting call
 * returned and nowhere else, which is why rotating is how a desk gets the link
 * back rather than reading it (ADR-035, ADR-058).
 *
 * There is no `expired` status: `expires_at` is the whole truth about expiry,
 * so nothing can be expired in fact and `issued` in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One per visit, enforced here.
            $table->foreignId('service_journey_id')->unique()->constrained('service_journeys')->restrictOnDelete();

            // Snapshotted from the journey so staff scoping and branch filters
            // are one indexed column instead of a join through two tables.
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // A guest has no customer record of their own to point at, and no
            // account: the capability is the identity (§3).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Traceability only. A review is never a financial record, and
            // nothing reads this to decide anything about money (§3).
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            /*
             * SHA-256 of the 256-bit secret — the public lookup:
             *
             *   SELECT * FROM review_invitations WHERE token_hash = ?
             */
            $table->char('token_hash', 64)->unique();

            $table->string('status', 16);

            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            // Who minted it: the system, after a visit completed, or the person
            // at the desk who reissued it.
            $table->string('issued_by_type', 16);
            $table->string('issued_by_id', 64)->nullable();
            $table->string('issued_by_label', 190)->nullable();

            $table->timestamps();

            /*
             * Invitations still live, for the staff screen and for any later
             * cleanup:
             *
             *   SELECT * FROM review_invitations
             *   WHERE status = 'issued' AND expires_at > ?
             */
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_invitations');
    }
};
