<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cash a drawer started the shift with.
 *
 * Phase 9 opened a shift without counting the drawer; reconciliation needs the
 * starting point (docs/20-FINANCE.md §§31–33). Expand only: nullable, no
 * backfill. A shift opened before this release, or at a center without Finance,
 * simply has no opening count — reconciliation treats that as zero and says so.
 *
 * Integer minor units in the center's currency, like every amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('opening_cash_minor')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->dropColumn('opening_cash_minor');
        });
    }
};
