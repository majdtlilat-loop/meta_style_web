<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every use of a membership benefit, append-only — the truth "uses left" is
 * proven from.
 *
 *   use        a member's price applied to a line at checkout
 *   reversal   that use given back: withdrawn, discarded, voided
 *
 *   used(benefit) = Σ use − Σ reversal
 *
 * `unique(source_type, source_uuid, kind)`: one use per benefit record, one
 * reversal of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_benefit_usages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_membership_id')->constrained('customer_memberships')->restrictOnDelete();
            $table->foreignId('customer_membership_benefit_id')->constrained('customer_membership_benefits')->restrictOnDelete();

            $table->string('kind', 16);
            $table->unsignedSmallInteger('quantity');

            $table->string('source_type', 16);
            $table->uuid('source_uuid');
            $table->uuid('sale_uuid')->nullable();

            $table->string('reason', 190)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();

            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->unique(['source_type', 'source_uuid', 'kind']);

            /*
             * Uses so far, per benefit:
             *
             *   SELECT kind, SUM(quantity) FROM membership_benefit_usages
             *   WHERE customer_membership_benefit_id = ? GROUP BY kind
             *
             * Named explicitly: the generated name is 67 characters, over
             * MySQL's 64-character identifier limit.
             */
            $table->index(['customer_membership_benefit_id', 'kind'], 'membership_usages_benefit_kind_index');

            /*
             * What a sale used, when it is voided or its draft discarded:
             *
             *   SELECT source_uuid FROM membership_benefit_usages
             *   WHERE sale_uuid = ? AND source_type = 'benefit' AND kind = 'use'
             */
            $table->index('sale_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_benefit_usages');
    }
};
