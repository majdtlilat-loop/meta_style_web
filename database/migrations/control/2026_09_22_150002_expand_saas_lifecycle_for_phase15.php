<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table): void {
            $table->string('slug', 63)->nullable()->unique()->after('public_key');
        });

        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->string('requested_slug', 63)->nullable()->unique()->after('center_name');
            $table->foreignId('selected_plan_id')->nullable()->after('country')->constrained('plans')->nullOnDelete();
            $table->timestamp('email_verified_at')->nullable()->after('selected_plan_id');
            $table->timestamp('verification_sent_at')->nullable()->after('email_verified_at');
            $table->timestamp('verification_expires_at')->nullable()->after('verification_sent_at');
        });

        Schema::connection($this->connection)->table('subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_minor_snapshot')->nullable()->after('plan_id');
            $table->char('currency_snapshot', 3)->nullable()->after('price_minor_snapshot');
            $table->string('billing_period_snapshot', 16)->nullable()->after('currency_snapshot');
            $table->json('plan_name_snapshot')->nullable()->after('billing_period_snapshot');
        });

        Schema::connection($this->connection)->create('tenant_lifecycle_history', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason');
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'occurred_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::connection($this->connection)->create('plan_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->string('billing_period', 16);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('changed_by_id', 64)->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->index(['plan_id', 'effective_from']);
        });

        Schema::connection($this->connection)->create('subscription_scheduled_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('target_plan_id')->constrained('plans');
            $table->timestamp('effective_at')->index();
            $table->string('status', 24)->default('scheduled')->index();
            $table->text('reason');
            $table->string('requested_by_id', 64)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('subscription_scheduled_changes');
        Schema::connection($this->connection)->dropIfExists('plan_price_history');
        Schema::connection($this->connection)->dropIfExists('tenant_lifecycle_history');

        Schema::connection($this->connection)->table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['price_minor_snapshot', 'currency_snapshot', 'billing_period_snapshot', 'plan_name_snapshot']);
        });

        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('selected_plan_id');
            $table->dropUnique(['requested_slug']);
            $table->dropColumn(['requested_slug', 'email_verified_at', 'verification_sent_at', 'verification_expires_at']);
        });

        Schema::connection($this->connection)->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
