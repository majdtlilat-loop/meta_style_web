<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->index(['branch_id', 'created_at'], 'appointments_branch_created_index');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['branch_id', 'status', 'succeeded_at'], 'payments_branch_status_succeeded_index');
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['branch_id', 'status', 'succeeded_at'], 'refunds_branch_status_succeeded_index');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->index(['occurred_at', 'context_uuid'], 'loyalty_occurred_context_index');
        });

        Schema::table('package_transactions', function (Blueprint $table): void {
            $table->index(['occurred_at', 'sale_uuid'], 'package_occurred_sale_index');
        });

        Schema::table('membership_benefit_usages', function (Blueprint $table): void {
            $table->index(['occurred_at', 'sale_uuid'], 'membership_usage_occurred_sale_index');
        });
    }

    public function down(): void
    {
        Schema::table('membership_benefit_usages', fn (Blueprint $table) => $table->dropIndex('membership_usage_occurred_sale_index'));
        Schema::table('package_transactions', fn (Blueprint $table) => $table->dropIndex('package_occurred_sale_index'));
        Schema::table('loyalty_transactions', fn (Blueprint $table) => $table->dropIndex('loyalty_occurred_context_index'));
        Schema::table('refunds', fn (Blueprint $table) => $table->dropIndex('refunds_branch_status_succeeded_index'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_branch_status_succeeded_index'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_branch_created_index'));
    }
};
