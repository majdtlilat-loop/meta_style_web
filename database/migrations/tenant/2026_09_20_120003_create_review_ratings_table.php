<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional detail scores: this service, and the person who performed it.
 *
 * Rows rather than JSON on the review, because these are the columns the
 * averages are grouped by — and an aggregate over a JSON document is a scan
 * that gets slower with every review a center collects
 * (docs/22-REVIEWS.md §10).
 *
 * `journey_stage_id` is NOT NULL and is the PROOF. The service and the employee
 * are resolved from the stage at submission, never taken from the request, so a
 * customer cannot rate a service that was skipped, an employee who was only
 * booked, or anything from somebody else's visit (§§11–12).
 *
 * `unique(review_id, dimension, journey_stage_id)` is one score per dimension
 * per performed service — a double submit cannot double-weight a stylist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_ratings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();

            $table->string('dimension', 16);

            $table->foreignId('journey_stage_id')->constrained('journey_stages')->restrictOnDelete();

            // Denormalised from the stage so the averages are one grouped query
            // rather than a join per row (§24).
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->unsignedTinyInteger('rating');

            $table->dateTime('created_at');

            $table->unique(['review_id', 'dimension', 'journey_stage_id']);

            /*
             * Every rated service, or every rated employee, in one pass:
             *
             *   SELECT service_id, COUNT(*), SUM(rating) FROM review_ratings
             *   WHERE dimension = 'service' AND review_id IN (...) GROUP BY service_id
             */
            $table->index(['dimension', 'service_id']);
            $table->index(['dimension', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_ratings');
    }
};
