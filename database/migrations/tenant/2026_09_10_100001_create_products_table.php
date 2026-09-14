<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products: things a center sells over the counter that are not a service.
 *
 * ## The smallest thing that can be sold, and nothing more
 *
 * A shampoo bottle at the till needs a name, a price, and a way to find it —
 * which, at a counter with a scanner, is a barcode. That is this table.
 *
 * No stock level, no cost price, no supplier, no batch, no expiry, no warehouse.
 * Every one of those is Inventory, which is its own module with its own design,
 * and a `stock_quantity` column added "for now" would be the first line of an
 * inventory system nobody designed (docs/18-SALES.md §8).
 *
 * Every product is sellable at every branch. Per-branch availability is a real
 * requirement for some centers and not for Phase 9: it arrives with a stated
 * need, the way services grew `available_at_all_branches`.
 *
 * ## No currency column
 *
 * The same rule as `services.price_minor`: the currency lives in the center's
 * configuration, and a per-row currency would allow a catalog that mixes them.
 * A SALE snapshots the currency at the moment something is charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->json('name');

            /*
             * Both optional, both unique when present. Both engines allow many
             * NULLs in a unique index, so a center that never scans anything
             * leaves them empty.
             *
             *   SELECT ... FROM products WHERE barcode = ? AND is_active = 1
             *
             * is what a scanner at the till runs, which is why it is an index
             * and not a LIKE.
             */
            $table->string('sku', 64)->nullable()->unique();
            $table->string('barcode', 64)->nullable()->unique();

            // IQD exponent 0: 12,000 dinars is the integer 12000.
            $table->unsignedBigInteger('price_minor');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // DATETIME, nullable. Archived rather than deleted: a sale line
            // references what was sold, and history must still resolve.
            $table->dateTime('archived_at')->nullable();

            $table->timestamps();

            /*
             * The POS product list, verbatim:
             *
             *   SELECT ... FROM products
             *   WHERE is_active = 1 AND archived_at IS NULL
             *   ORDER BY sort_order, id
             */
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
