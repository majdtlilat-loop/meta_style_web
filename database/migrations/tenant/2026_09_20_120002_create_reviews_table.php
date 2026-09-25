<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one customer said about one completed visit.
 *
 * TWO unique columns, and both are deliberate: `service_journey_id`, because a
 * visit has one review, and `review_invitation_id`, because an invitation
 * produces one. Two simultaneous submissions of the same link therefore end
 * with one row whichever of them commits first — the database decides, not a
 * check the second request ran a moment before the first one wrote
 * (docs/22-REVIEWS.md §14).
 *
 * `overall_rating` is a column rather than a row in `review_ratings`, so the
 * visit's score can never be missing, duplicated or disagreed with. The
 * per-service and per-employee detail is optional and lives there (§9).
 *
 * Moderation writes `status`, `moderated_*` and nothing else: the customer's
 * words are never rewritten (§15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('review_invitation_id')->unique()->constrained('review_invitations')->restrictOnDelete();
            $table->foreignId('service_journey_id')->unique()->constrained('service_journeys')->restrictOnDelete();

            // Snapshotted from the journey: branch scoping is the first filter
            // on every staff query and every summary (§13).
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('status', 16);

            // 1..5, validated in the Action. A small integer, never a float:
            // averages are computed, never stored (§25).
            $table->unsignedTinyInteger('overall_rating');

            // Customer-authored, bounded, escaped on render, never HTML (§56).
            $table->text('public_comment')->nullable();

            $table->dateTime('submitted_at');

            $table->dateTime('moderated_at')->nullable();
            $table->string('moderated_by_id', 64)->nullable();
            $table->string('moderated_by_label', 190)->nullable();
            $table->string('moderation_reason', 190)->nullable();

            $table->timestamps();

            /*
             * The staff list and every rating summary:
             *
             *   SELECT ... FROM reviews
             *   WHERE branch_id = ? AND status IN (...) AND submitted_at BETWEEN ? AND ?
             */
            $table->index(['branch_id', 'status', 'submitted_at']);

            /*
             * Filtering the list by score, and finding the low ones:
             *
             *   SELECT ... FROM reviews WHERE status IN (...) AND overall_rating <= ?
             */
            $table->index(['status', 'overall_rating']);

            /*
             * One customer's history, on their profile:
             *
             *   SELECT ... FROM reviews WHERE customer_id = ? ORDER BY submitted_at DESC
             */
            $table->index(['customer_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
