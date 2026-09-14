<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal branch foundation.
 *
 * Present in Phase 3 for two concrete reasons: self-registration must create a
 * main branch, and branch scope is part of authorization. Everything else about
 * branches — working hours, holidays, phones, address, payment setup, menu
 * appearance — is Phase 4 and is deliberately NOT stubbed here.
 *
 * Note the absence of a `tenant_id` column. Branch ownership is implicit in
 * which database the row lives in; a redundant tenant column would be a second
 * source of truth and an invitation to filter by it instead of by connection
 * (docs/02-TENANCY.md §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable from the start: a center in Iraq will want Arabic
            // and English branch names, and retrofitting a JSON column onto a
            // populated table costs an expand/contract cycle we can simply
            // avoid (docs/07-LOCALIZATION.md §3.2).
            $table->json('name');

            $table->boolean('is_active')->default(true);

            // Exactly one main branch per tenant, created at provisioning.
            $table->boolean('is_main')->default(false);

            // Booking maths happens in BRANCH-local time, not tenant or server
            // time — a center with branches in two timezones must work on day
            // one (docs/10-API-FOUNDATION.md §8).
            $table->string('timezone', 64)->default('Asia/Baghdad');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
