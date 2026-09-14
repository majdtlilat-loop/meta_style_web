<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans are DATA, not code.
 *
 * A plan is a row plus a set of entitlement grants. Adding "Business + Queue
 * add-on" must never require a code change — that is the whole point of
 * docs/05-ENTITLEMENTS.md §9, and why `if ($plan === 'pro')` is banned.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 64)->unique();
            $table->json('name');
            $table->json('description')->nullable();

            // Money as integer minor units; the exponent comes from the
            // currency, never assumed to be 2 (IQD has none).
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('IQD');
            $table->string('billing_period', 16)->default('monthly');

            // Null means "use the platform default trial length".
            $table->unsignedSmallInteger('trial_days')->nullable();

            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            // A code from the catalog in config/entitlements.php. Deliberately
            // not a foreign key: the catalog is owned by application code, and
            // a deprecated key must still resolve for historical plans
            // (docs/DECISIONS.md ADR-006).
            $table->string('entitlement', 64);
            $table->timestamps();

            $table->unique(['plan_id', 'entitlement']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('plan_entitlements');
        Schema::connection($this->connection)->dropIfExists('plans');
    }
};
