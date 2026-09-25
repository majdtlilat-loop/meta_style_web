<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SaaS invoice records the plan and billing cycle it charges for, an
 * optional external reference, and can be voided — never deleted. Expand-only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('saas_invoices', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('subscription_id')->constrained('plans')->nullOnDelete();
            $table->json('plan_name_snapshot')->nullable()->after('plan_id');
            $table->string('billing_period', 16)->nullable()->after('plan_name_snapshot');
            $table->string('reference', 190)->nullable()->after('notes');
            $table->timestamp('voided_at')->nullable()->after('settled_at');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->string('voided_by_label', 190)->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('saas_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['plan_name_snapshot', 'billing_period', 'reference', 'voided_at', 'void_reason', 'voided_by_label']);
        });
    }
};
