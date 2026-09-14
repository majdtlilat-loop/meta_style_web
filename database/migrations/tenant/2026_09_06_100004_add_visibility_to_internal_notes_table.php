<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who, among staff, may read a given note.
 *
 * Phase 4 built the notes seam with one implicit audience: anyone who could see
 * the thing the note was on. Customer notes need one more level — "this
 * customer disputed a charge and was abusive to the stylist" is a note a
 * manager needs and a note that should not be on the screen reception turns
 * toward the customer (docs/13-ROADMAP.md Phase 5 §11).
 *
 * TWO LEVELS, NOT FOUR. `internal` and `manager_only` are the ones with a real
 * consumer today. `customer_visible` is the interesting future case and is
 * deliberately absent: it needs a customer-facing surface to appear on, a
 * different authoring flow, and a decision about editing after the customer has
 * read it. Adding the case now would be three columns of guesswork.
 *
 * Expand-only: defaulted, so the release runs against tenants still serving the
 * old code, and every existing Phase 4 note becomes `internal` — which is what
 * it already was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_notes', function (Blueprint $table): void {
            $table->string('visibility', 24)->default('internal')->after('body')->index();
        });
    }

    public function down(): void
    {
        Schema::table('internal_notes', function (Blueprint $table): void {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
    }
};
