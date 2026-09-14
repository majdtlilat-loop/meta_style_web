<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant grants and revocations layered over the plan.
 *
 * This is what lets Super Admin sell "Business Plan + Queue add-on", hand a
 * capability to one center for support reasons, or take one away for abuse —
 * none of which should require a new plan or a code change
 * (docs/05-ENTITLEMENTS.md §5).
 *
 * Note it does NOT touch the tenant schema. Every tenant database stays
 * identical regardless of what it owns; entitlements gate at runtime.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_entitlement_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('entitlement', 64);

            // grant | revoke. A revoke must be able to beat a plan grant,
            // otherwise abuse control means editing someone's plan.
            $table->string('mode', 16);

            $table->string('source', 32)->default('support');
            $table->text('reason')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entitlement']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_entitlement_overrides');
    }
};
