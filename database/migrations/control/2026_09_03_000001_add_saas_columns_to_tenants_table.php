<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two columns the SaaS layer needs on every tenant.
 *
 * Expand-only: both are nullable, so code running against the previous schema
 * keeps working (docs/03-DATABASE-MIGRATIONS.md §2.1).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table): void {
            // The opaque public identifier a client may present at login to say
            // WHICH center it is authenticating against. Safe to expose: it is
            // random, revocable, and grants nothing without valid credentials.
            //
            // The internal `sequence` and the row id are never used this way —
            // the sequence leaks signup volume, and neither is revocable.
            $table->string('public_key', 40)->nullable()->unique()->after('sequence');

            // Bumped whenever anything that could change a tenant's effective
            // entitlements changes. Cache keys embed it, so invalidation is one
            // integer increment rather than a fan-out sweep
            // (docs/05-ENTITLEMENTS.md §5.2).
            $table->unsignedInteger('entitlements_version')->default(1)->after('schema_version');

            // Per-tenant trial length, overriding the plan and the platform
            // default. Null means "use the normal rules" — see TrialPolicy.
            $table->unsignedSmallInteger('trial_days_override')->nullable()->after('entitlements_version');
        });

        // Backfill the handful of tenants that predate this column. Safe to do
        // inline only because the table is tiny; a real backfill belongs in a
        // queued chunked job (docs/03-DATABASE-MIGRATIONS.md §2.3).
        $tenants = DB::connection($this->connection)->table('tenants')->whereNull('public_key')->pluck('id');

        foreach ($tenants as $id) {
            DB::connection($this->connection)
                ->table('tenants')
                ->where('id', $id)
                ->update(['public_key' => 'ctr_'.Str::lower(Str::random(32))]);
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['public_key']);
            $table->dropColumn(['public_key', 'entitlements_version', 'trial_days_override']);
        });
    }
};
