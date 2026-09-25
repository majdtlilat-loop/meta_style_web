<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benefit discounts on a draft sale.
 *
 * A benefit — redeemed points, a member's price, a service a package covers — is
 * a fixed discount the owning module applies through `SaleBenefits`
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22). Three nullable columns, expand
 * only, nothing backfilled; every existing adjustment keeps NULL in all three
 * and prices exactly as before:
 *
 *   sale_item_id      the one line the discount belongs to. Cascades with the
 *                     line, as every adjustment already cascades with its sale;
 *                     a line carrying a benefit cannot be removed while it does.
 *   source_type       which module owns it — opaque to Sales.
 *   source_reference  that module's uuid for it.
 *
 * `unique(source_type, source_reference)`: one discount per benefit record, so a
 * double-clicked "apply" cannot discount twice. NULLs do not collide, so manual
 * adjustments are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_adjustments', function (Blueprint $table): void {
            $table->foreignId('sale_item_id')->nullable()->after('sale_id')
                ->constrained('sale_items')->cascadeOnDelete();
            $table->string('source_type', 32)->nullable()->after('position');
            $table->uuid('source_reference')->nullable()->after('source_type');

            $table->unique(['source_type', 'source_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_adjustments', function (Blueprint $table): void {
            $table->dropUnique(['source_type', 'source_reference']);
            $table->dropConstrainedForeignId('sale_item_id');
            $table->dropColumn(['source_type', 'source_reference']);
        });
    }
};
