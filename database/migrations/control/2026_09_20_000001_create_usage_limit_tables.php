<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial half of metering: what a center is ALLOWED, and what it has
 * used as the platform last saw it.
 *
 * Deliberately shaped like the entitlement tables beside it
 * (`plan_entitlements`, `tenant_entitlement_overrides`), because it answers the
 * same kind of question one step further on: entitlements say WHETHER a center
 * owns a capability, limits say HOW MUCH of it they bought
 * (docs/26-USAGE-QUOTAS.md §§2–4).
 *
 * ## Allowances live here; counters live in the tenant
 *
 * A quota decision on the hot path must never cross databases. So the
 * authoritative running total is a row in the CENTER's own database, and what
 * lives here is the allowance it was told to use. The center's counter takes a
 * SNAPSHOT of that allowance when its period opens, and a reconciler keeps the
 * snapshot honest afterwards (§§5, 7).
 *
 * ## And the projection is only a report
 *
 * `tenant_usage_projections` exists so Super Admin can see every center on one
 * screen without opening every database. It is derived, lagging and
 * non-authoritative. NOTHING reads it to make a decision — a quota check that
 * consulted it would be deciding from a stale copy, which is exactly the bug
 * the tenant-side counter exists to prevent (§9).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        /*
         * What a PLAN includes.
         *
         * `allowance` NULL means UNLIMITED — not "unset". A plan row exists
         * precisely to state a number, and the absence of a row is what means
         * "this plan says nothing, fall back to the system default" (§4).
         */
        Schema::connection($this->connection)->create('plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            // A code from config/usage.php. Not a foreign key, for the same
            // reason `plan_entitlements.entitlement` is not: the catalog is
            // owned by configuration, and a retired code must still resolve for
            // historical plans (ADR-006).
            $table->string('resource', 64);

            $table->unsignedBigInteger('allowance')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'resource']);
        });

        /*
         * What ONE center gets instead, whatever their plan says.
         *
         * A sales concession, a pilot, a temporary raise during a busy month.
         * Overrides beat the plan in both directions — this is also how an
         * abusive center is throttled without moving them off their plan.
         */
        Schema::connection($this->connection)->create('tenant_limit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('resource', 64);

            $table->unsignedBigInteger('allowance')->nullable();

            /*
             * A monotonically increasing stamp for THIS tenant's allowances.
             *
             * The tenant's counter records which version its snapshot came
             * from, so the reconciler can tell "already applied" from "changed
             * since" without comparing numbers — comparing numbers cannot
             * distinguish a stale copy from a deliberate mid-period decrease
             * that has not taken effect yet (§8).
             */
            $table->unsignedBigInteger('version')->default(1);

            /*
             * A DECREASE normally applies only from the next period, so a
             * center cannot be cut off mid-month by a pricing change they have
             * already paid for. This is the audited escape hatch for the case
             * that has to act now — abuse, a compromised account (§6).
             */
            $table->boolean('enforce_immediately')->default(false);

            $table->string('reason', 190)->nullable();
            $table->string('set_by_id', 64)->nullable();
            $table->string('set_by_label', 190)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'resource']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        /*
         * The Super Admin read model. Reporting only (§9).
         */
        Schema::connection($this->connection)->create('tenant_usage_projections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('resource', 64);

            $table->dateTime('period_start');
            $table->dateTime('period_end');

            $table->unsignedBigInteger('used')->default(0);

            // NULL = unlimited, and therefore `percent` is NULL too. A
            // percentage of unlimited is not 0 and not 100; it does not exist,
            // and storing either would put a wrong number on a dashboard (§9).
            $table->unsignedBigInteger('allowance')->nullable();
            $table->unsignedSmallInteger('percent')->nullable();

            $table->string('status', 16)->default('normal');

            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('projected_at');

            $table->timestamps();

            /*
             * One row per tenant, resource and period — so re-projecting is an
             * upsert and a re-run writes nothing new.
             *
             * Named explicitly: the generated name for four columns on this
             * table exceeds 64 characters, which fails provisioning mid-migration
             * and then reports "table already exists" on every retry
             * (docs/03-DATABASE-MIGRATIONS.md §7.2).
             */
            $table->unique(['tenant_id', 'resource', 'period_start'], 'tenant_usage_proj_unique');

            // The Super Admin list: "who is near their limit right now".
            $table->index(['status', 'percent'], 'tenant_usage_proj_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_usage_projections');
        Schema::connection($this->connection)->dropIfExists('tenant_limit_overrides');
        Schema::connection($this->connection)->dropIfExists('plan_limits');
    }
};
