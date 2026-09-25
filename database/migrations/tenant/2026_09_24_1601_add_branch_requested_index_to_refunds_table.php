<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Manager's receipts list shows a branch's refunds requested in a
 * branch-local window, newest first, whatever their state:
 *
 *   SELECT … FROM refunds
 *   WHERE branch_id = ? AND requested_at >= ? AND requested_at < ?
 *   ORDER BY requested_at DESC, id DESC LIMIT 25
 *
 * `refunds(branch_id, status, succeeded_at)` cannot serve it (a pending or
 * failed refund has no succeeded_at), and the branch FK index alone makes the
 * window a scan of every refund the branch ever made. Expand-only, portable,
 * explicitly named (docs/03-DATABASE-MIGRATIONS.md §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['branch_id', 'requested_at'], 'refunds_branch_requested_index');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', fn (Blueprint $table) => $table->dropIndex('refunds_branch_requested_index'));
    }
};
