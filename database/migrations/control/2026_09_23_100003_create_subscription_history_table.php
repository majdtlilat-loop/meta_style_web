<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription's commercial history: plan, cycle, status and renewal
 * changes, one append-only row each, with who did it and why.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('subscription_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->uuid('tenant_id')->index();
            $table->string('event', 48);
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('from_cycle', 16)->nullable();
            $table->string('to_cycle', 16)->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('plan_name')->nullable();
            $table->json('details')->nullable();
            $table->text('reason')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['subscription_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('subscription_history');
    }
};
