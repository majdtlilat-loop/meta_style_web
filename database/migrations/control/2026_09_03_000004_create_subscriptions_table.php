<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One subscription per tenant, carrying the lifecycle state that decides
 * whether a tenant may act at all (docs/00-PRODUCT-OVERVIEW.md §6).
 *
 * Deliberately NOT a billing system. No invoices, no payments, no proration —
 * Meta Style's own SaaS billing is Phase 10, and building ledgers before there
 * is anything to bill is how a phase absorbs the next one.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One active subscription per tenant for now; the unique index is
            // what enforces "self-registration cannot create two".
            $table->uuid('tenant_id')->unique();
            $table->foreignId('plan_id')->constrained('plans');

            $table->string('status', 32)->index();

            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable()->index();

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // Grace after a failed payment: access continues, warning shown.
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('subscriptions');
    }
};
