<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The short code a branch's invoice numbers start with: `BG-2026-000017`.
 *
 * Invoices are numbered PER BRANCH (docs/13-ROADMAP.md Phase 9), so two branches
 * both issue their own "000001" every January. The prefix is what makes those
 * two documents distinguishable on paper and in a customer's hand — and it is
 * what lets `invoices.number` carry a center-wide unique index at all.
 *
 * Nullable, because a single-branch center should not have to configure
 * anything before its first sale: the MAIN branch falls back to `INV`, and
 * `INV` is reserved for it. A second branch must be given its own prefix before
 * it can issue invoices, which the finalization Action says in plain words
 * (docs/18-SALES.md §17).
 *
 * Owned by Sales, stored on the branch: the same precedent Phase 8 set with
 * `departments.queue_prefix`. It is branch configuration, and a separate table
 * for one column would be a second place to look for one answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            // Unique when present; many NULLs are allowed on both engines.
            $table->string('invoice_prefix', 4)->nullable()->unique()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['invoice_prefix']);
            $table->dropColumn('invoice_prefix');
        });
    }
};
