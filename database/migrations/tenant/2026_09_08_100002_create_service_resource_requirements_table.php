<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a service needs in order to be performed.
 *
 * "Laser Session requires 1 Treatment Room and 1 Laser Machine." Two rows.
 *
 * ## Against the TYPE, never a concrete resource
 *
 * A service that named Laser Machine 2 would be unbookable the day the center
 * buys a third machine, and a center with two machines would need two copies of
 * the service. Booking resolves the type to a concrete resource at the moment
 * it books, which is the only moment the answer is knowable
 * (docs/13-ROADMAP.md Phase 7 §5).
 *
 * ## The unique key is the model
 *
 * One row per (service, type). "Two treatment rooms" is `quantity = 2`, not two
 * rows — which makes the requirement a single number the allocator can satisfy
 * from one resource with spare capacity or from several exclusive ones.
 *
 * ## Changing a requirement does not rewrite history
 *
 * Existing appointments keep the concrete reservations they were given. New
 * bookings use the current requirements. Nothing here is snapshotted onto the
 * service, because the reservation rows already are (§43, §36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_resource_requirements', function (Blueprint $table): void {
            $table->id();

            // The requirement belongs to the service; retiring the service
            // takes it with it. Archiving does not, which is the normal path.
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            // restrictOnDelete: a type still required by a service must be
            // archived, not deleted, or the requirement would point at nothing.
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();

            $table->unsignedTinyInteger('quantity')->default(1);
            $table->timestamps();

            // Both the lookup key ("what does this service need?") and the
            // correctness constraint — one requirement per type, so quantity is
            // never split across rows that could disagree.
            $table->unique(['service_id', 'resource_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_resource_requirements');
    }
};
