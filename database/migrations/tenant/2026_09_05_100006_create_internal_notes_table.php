<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal operational notes. Staff-only, on services and departments.
 *
 * THE INTEGRATION POINT FOR THE FUTURE NOTES ENGINE (docs/13-ROADMAP.md).
 * Meta Style's locked requirement is that notes eventually attach to customers,
 * bookings, order items, services, departments, journey stages, staff and
 * invoices. Seven of those nine do not exist yet, so building the engine now
 * would mean designing against imagined requirements.
 *
 * What is built instead is the SHAPE that engine needs — a note row keyed by a
 * stable owner string — restricted to the two owners that exist. Adding
 * `customer` in Phase 5 is a new enum case, not a new table and not a
 * migration of existing rows.
 *
 * NEVER PUBLIC. Nothing in this table reaches the electronic menu. That is
 * enforced by the public resources listing their fields explicitly rather than
 * excluding this one — the difference matters, because an allow-list cannot be
 * defeated by adding a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // 'service' | 'department' today. The same stable-string reasoning
            // as media_items: a class name would not survive a namespace move.
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');

            // Plain text, not translatable. A note is written by one member of
            // staff for their colleagues, in whatever language they share;
            // asking for it in three languages would mean it gets written in
            // none.
            $table->text('body');

            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notes');
    }
};
