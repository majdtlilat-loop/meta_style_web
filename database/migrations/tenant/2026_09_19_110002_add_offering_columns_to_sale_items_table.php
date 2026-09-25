<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offering lines: something another module sells through the till.
 *
 * A membership plan or a service package is sold as an ordinary sale line — the
 * same pricing, invoice and payment as a haircut — and the line records which
 * catalog it came from and which item, so the owning module can act on it once
 * the invoice is settled (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§11, 15).
 *
 * Expand only, nullable, nothing backfilled. No index: they are read only for
 * the lines of ONE sale, which `(sale_id, position)` already serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('offering_type', 32)->nullable()->after('product_id');
            $table->uuid('offering_reference')->nullable()->after('offering_type');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['offering_type', 'offering_reference']);
        });
    }
};
