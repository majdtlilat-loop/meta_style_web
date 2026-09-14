<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales: the commercial transaction.
 *
 * ## Five concepts, permanently separate
 *
 *     Appointment     what was RESERVED
 *     ServiceJourney  the operational VISIT
 *     JourneyStage    what was actually PERFORMED
 *     QueueTicket     the waiting and calling around a stage
 *     Sale            what was CHARGED
 *
 * A sale may point at a visit. It never becomes one, and nothing here is
 * written back onto the appointment, journey or queue tables — financial state
 * on an operational row is the first step towards "we changed the price and the
 * visit history changed with it" (docs/18-SALES.md §1).
 *
 * ## A draft IS the cart
 *
 * `draft → finalized → voided`. There is no second cart domain: a draft sale is
 * mutable, has no number and no invoice, and becomes a financial record only
 * when it is finalized. There is also no payment state — "unpaid", "partially
 * paid" and "settled" belong to Payment, in Phase 10 (§5).
 *
 * ## Every line is a SNAPSHOT
 *
 * Name, variation, add-ons, quantity, unit price — copied at the moment the line
 * was added, from the catalog or from the visit's own snapshot. Changing a
 * service's price tomorrow must not change what somebody was charged today, and
 * the finalized invoice must not be recomputed from a catalog that has moved
 * on (§§11, 37).
 *
 * ## Every instant is DATETIME
 *
 * The MariaDB TIMESTAMP trap again: the first non-nullable TIMESTAMP column in a
 * table silently gains ON UPDATE CURRENT_TIMESTAMP (ADR-046).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Nullable: an anonymous counter sale has nobody to attach.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            /*
             * The visit this sale charges for, if any. Kept after a void, so
             * "what was charged for this visit, including what was voided" stays
             * answerable.
             */
            $table->foreignId('service_journey_id')->nullable()
                ->constrained('service_journeys')->nullOnDelete();

            /*
             * ONE LIVE SALE PER VISIT, as a database invariant.
             *
             * Equal to `service_journey_id` while the sale is a draft or
             * finalized, NULL once it is voided. Opening checkout on the same
             * visit twice collides here and is turned into "here is the sale
             * that already exists"; voiding releases it so the visit can be
             * charged correctly afterwards. The Queue's
             * `active_journey_stage_id` shape (ADR-033).
             *
             *   SELECT ... FROM sales WHERE active_journey_id = ?
             */
            $table->unsignedBigInteger('active_journey_id')->nullable()->unique();

            /*
             * pos · journey_checkout · walk_in_checkout
             *
             * Stored, not inferred from which relations happen to be null: a
             * report asking "how much did walk-ins spend" must not depend on a
             * visit row still existing (§30).
             */
            $table->string('source', 24);

            // draft · finalized · voided
            $table->string('status', 16)->default('draft');

            // One currency per sale, snapshotted at creation (§40).
            $table->string('currency', 3);

            /*
             * Totals, in minor units, written ONLY by the pricing service.
             *
             *   subtotal − discount_total + surcharge_total + tax_total = grand_total
             *
             * `tax_total` is always 0: there is no tax requirement and no guessed
             * VAT. It exists so that the day there is one, the invoice already
             * has somewhere to put it (§41).
             */
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_total_minor')->default(0);
            $table->unsignedBigInteger('surcharge_total_minor')->default(0);
            $table->unsignedBigInteger('tax_total_minor')->default(0);
            $table->unsignedBigInteger('grand_total_minor')->default(0);

            // Stamped at finalization: which till session produced this sale.
            $table->foreignId('cashier_shift_id')->nullable()
                ->constrained('cashier_shifts')->nullOnDelete();

            /*
             * A client-supplied token for "open a new sale". A double-tapped
             * button collides and gets the draft it already made.
             */
            $table->string('idempotency_token', 64)->nullable()->unique();

            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->dateTime('finalized_at')->nullable();
            $table->string('finalized_by_id', 64)->nullable();
            $table->string('finalized_by_label', 190)->nullable();

            $table->dateTime('voided_at')->nullable();
            $table->string('voided_by_id', 64)->nullable();
            $table->string('voided_by_label', 190)->nullable();
            $table->string('void_reason', 190)->nullable();

            $table->timestamps();

            /*
             * Open drafts at a branch:
             *
             *   SELECT ... FROM sales WHERE branch_id = ? AND status = 'draft'
             */
            $table->index(['branch_id', 'status']);

            /*
             * Issued sales by day, bounded. Drafts have no `finalized_at` and
             * fall out of the range by construction:
             *
             *   SELECT ... FROM sales
             *   WHERE branch_id = ? AND finalized_at >= ? AND finalized_at < ?
             *   ORDER BY finalized_at DESC LIMIT n
             */
            $table->index(['branch_id', 'finalized_at']);
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            // service · product · custom
            $table->string('kind', 16);

            /*
             * Where the line came from. All nullable, all references rather than
             * sources of truth: a renamed or archived service must not change a
             * line, which is why the name and price below are copies.
             */
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('service_variation_id')->nullable()
                ->constrained('service_variations')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            /*
             * The PERFORMED stage this line charges for. One charge line per
             * stage per sale — see the unique index below.
             */
            $table->foreignId('journey_stage_id')->nullable()
                ->constrained('journey_stages')->nullOnDelete();

            // Who performed it, copied from the stage. The seam commissions
            // will need in Phase 10; nothing reads it yet.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->json('name');
            $table->json('variation_name')->nullable();

            $table->unsignedSmallInteger('quantity')->default(1);

            /*
             * THE PRICE, TWICE, on purpose.
             *
             * `original_unit_price_minor` is what the source said — the catalog,
             * or the visit's own snapshot — and it is never overwritten.
             * `unit_price_minor` is what is actually charged. They differ only
             * through an explicit override that carries an actor and a reason,
             * so "what did the catalog say, and who changed it" is always
             * recoverable (§13).
             */
            $table->unsignedBigInteger('original_unit_price_minor');
            // catalog · journey · manual
            $table->string('price_source', 16);
            $table->unsignedBigInteger('unit_price_minor');

            $table->string('price_override_reason', 190)->nullable();
            $table->string('price_overridden_by_id', 64)->nullable();
            $table->string('price_overridden_by_label', 190)->nullable();

            // Σ add-on unit prices, per ONE unit of this line.
            $table->unsignedBigInteger('addons_unit_total_minor')->default(0);

            // (unit_price + addons_unit_total) × quantity
            $table->unsignedBigInteger('line_subtotal_minor')->default(0);

            /*
             * This line's share of the sale-level discounts, allocated by the
             * largest-remainder method so the shares sum EXACTLY to the
             * discount total. Nothing reads it for a total; it is what makes a
             * per-line revenue question answerable later without re-deriving a
             * rounding decision nobody recorded (§10).
             */
            $table->unsignedBigInteger('discount_allocated_minor')->default(0);
            $table->unsignedBigInteger('line_total_minor')->default(0);

            $table->string('currency', 3);

            // Staff-facing. Never printed on the invoice.
            $table->string('note', 190)->nullable();

            $table->timestamps();

            /*
             * One charge per performed stage per sale. NULLs do not collide, so
             * catalog and product lines are unconstrained.
             */
            $table->unique(['sale_id', 'journey_stage_id']);

            /*
             * The cart, in order:
             *
             *   SELECT ... FROM sale_items WHERE sale_id = ? ORDER BY position
             */
            $table->index(['sale_id', 'position']);
        });

        Schema::create('sale_item_addons', function (Blueprint $table): void {
            $table->id();

            // The FK's own index serves `WHERE sale_item_id IN (...)`.
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('service_addon_id')->nullable()
                ->constrained('service_addons')->nullOnDelete();

            $table->json('name');
            $table->unsignedBigInteger('unit_price_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
        });

        Schema::create('sale_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A handful of rows per sale; the FK's own index serves the read.
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();

            // discount_fixed · discount_percent · surcharge_fixed
            $table->string('type', 24);

            /*
             * Percent adjustments store BASIS POINTS: 1250 is 12.5%. An integer,
             * because a float percentage is a rounding decision made by the
             * processor instead of by the product (§10).
             */
            $table->unsignedSmallInteger('basis_points')->nullable();

            // Fixed adjustments: the amount entered. Percent adjustments: the
            // amount it resolved to at the last recalculation.
            $table->unsignedBigInteger('amount_minor')->default(0);

            $table->string('reason', 190);

            $table->unsignedTinyInteger('position')->default(0);

            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_adjustments');
        Schema::dropIfExists('sale_item_addons');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
