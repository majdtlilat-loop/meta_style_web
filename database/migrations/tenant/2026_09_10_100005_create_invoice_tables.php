<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices: the immutable published document.
 *
 * ## An invoice is not a sale
 *
 * A sale is the transaction and can be voided. An invoice is what was PUBLISHED
 * about it, and is never edited again. So it carries its own copies of
 * everything it shows — center, branch, customer name, every line, every
 * adjustment, every total — and rendering it never reads a sale line, a catalog
 * row or a branch setting. A branch that moves, a service that is renamed or a
 * sale line somebody's code touches later cannot change a document a customer
 * already holds (docs/18-SALES.md §§14–16, ADR-054).
 *
 * Corrections are future void/credit flows. This table has no `status`, no
 * `voided_at`, nothing that would ever need an UPDATE: a void is recorded on the
 * SALE, and the invoice renders the fact from there.
 *
 * ## Numbering (ADR-055)
 *
 * Per branch, per branch-local calendar year, from a locked sequence row — the
 * pattern Queue proved. The increment is inside the finalization transaction, so
 * a rollback releases the number with everything else. Numbers are therefore
 * unique and gapless per branch-year for as long as invoices are never deleted
 * and nobody edits `invoice_sequences` by hand. That is the exact claim, and no
 * stronger one is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // The year the NUMBERING resets on: the branch-local CALENDAR year,
            // so a center in Baghdad rolls over at its own midnight. Named for
            // what it is, not as an accounting period nothing here has verified.
            $table->unsignedSmallInteger('sequence_year');

            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            /*
             * THE ROW THAT GETS LOCKED:
             *
             *   INSERT IGNORE → SELECT ... FOR UPDATE → +1 → write
             *
             * and the insert-or-ignore key. `SELECT MAX(sequence_number) + 1`
             * without a lock hands two tills the same invoice number.
             */
            $table->unique(['branch_id', 'sequence_year']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * One invoice per sale, as a database invariant: a double-clicked
             * "finalize" that somehow passed the sale lock still cannot publish
             * a second document.
             */
            $table->foreignId('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            /*
             * "BG-2026-000017", composed once and stored. Unique across the
             * center: the branch prefix makes that true by construction, and
             * this index makes it true even if the construction is ever wrong.
             */
            $table->string('number', 32)->unique();
            $table->string('prefix', 4);
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedInteger('sequence_number');

            $table->dateTime('issued_at');

            // The branch's zone AT ISSUE, so a later timezone change on the
            // branch cannot move the printed time of an old invoice.
            $table->string('issued_timezone', 64);

            /*
             * SNAPSHOTS. Identity as it stood when the document was published.
             */
            $table->string('center_name', 190);
            $table->json('branch_name');
            $table->json('branch_address')->nullable();
            $table->string('branch_phone', 32)->nullable();

            // The customer's NAME, where there is a customer. Never a phone,
            // never an email: an invoice is handed over, photographed and
            // forwarded (§27).
            $table->string('customer_name', 190)->nullable();

            $table->string('currency', 3);

            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_total_minor');
            $table->unsignedBigInteger('surcharge_total_minor');
            $table->unsignedBigInteger('tax_total_minor');
            $table->unsignedBigInteger('grand_total_minor');

            /*
             * The adjustments exactly as they were applied: type, label, basis
             * points, amount. Render-only, never queried, a handful per
             * invoice — a JSON snapshot rather than a table nobody filters.
             */
            $table->json('adjustments');

            // Staff-facing only. Never on the customer's copy.
            $table->string('issued_by_id', 64)->nullable();
            $table->string('issued_by_label', 190)->nullable();

            $table->timestamps();

            /*
             * The numbering backstop behind the sequence lock: even a bug cannot
             * produce two "17"s in one branch-year.
             */
            $table->unique(['branch_id', 'sequence_year', 'sequence_number']);

            /*
             * A branch's invoices by day, bounded:
             *
             *   SELECT ... FROM invoices
             *   WHERE branch_id = ? AND issued_at >= ? AND issued_at < ?
             *   ORDER BY issued_at DESC LIMIT n
             */
            $table->index(['branch_id', 'issued_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->unsignedSmallInteger('position');

            // service · product · custom
            $table->string('kind', 16);

            $table->json('name');
            $table->json('variation_name')->nullable();

            // [{name: {...}, unit_price_minor: int}] — render-only.
            $table->json('addons');

            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('addons_unit_total_minor');
            $table->unsignedBigInteger('line_subtotal_minor');
            $table->unsignedBigInteger('discount_allocated_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->string('currency', 3);

            /*
             * References for later reporting, never read to RENDER. A deleted
             * service must not make an invoice unrenderable, which is why every
             * one of these is nullable and nulled on delete.
             */
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->timestamps();

            /*
             * One invoice, in order:
             *
             *   SELECT ... FROM invoice_items WHERE invoice_id = ? ORDER BY position
             */
            $table->index(['invoice_id', 'position']);
        });

        Schema::create('invoice_share_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            /*
             * SHA-256 of the secret, never the secret. The secret is 64 hex
             * characters from `random_bytes(32)` — 256 bits, so guessing one is
             * not a strategy — and exists only in the URL handed to the
             * customer. A copy of this table therefore opens no invoice: the
             * rule registration and staff activation tokens already follow.
             *
             * The public route resolves ONLY this — never an id, never a number —
             * so invoices cannot be enumerated:
             *
             *   SELECT ... FROM invoice_share_links
             *   WHERE token_hash = sha256(presented) AND active_invoice_id IS NOT NULL
             */
            $table->char('token_hash', 64)->unique();

            /*
             * ONE LIVE LINK PER INVOICE. Equal to `invoice_id` while the link
             * works and NULL once revoked, so rotation is "revoke, then create",
             * and a second live link collides.
             *
             * The link lives in its own table because the invoice row is
             * immutable: a rotatable credential cannot be a column on a document
             * that is never updated.
             */
            $table->unsignedBigInteger('active_invoice_id')->nullable()->unique();

            $table->dateTime('revoked_at')->nullable();

            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_share_links');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
