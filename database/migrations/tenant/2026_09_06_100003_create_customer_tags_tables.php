<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Center-defined labels on customers: VIP, New, Frequent, Follow Up.
 *
 * Manual only. No rules, no automatic segmentation, no campaign engine — a tag
 * is something a member of staff puts on a record, and later Marketing and
 * Reports read (docs/13-ROADMAP.md Phase 5 §12).
 *
 * Translatable names, for the same reason every other center-authored label is:
 * a center running Arabic and Kurdish writes "VIP" once per language or its
 * staff read English in an otherwise Arabic screen. It is the same cast used
 * throughout, so it costs nothing extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->json('name');

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Archive rather than delete: a tag that has been applied is part of
            // how past records were classified.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
        });

        Schema::create('customer_customer_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();

            $table->unique(['customer_id', 'customer_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_customer_tag');
        Schema::dropIfExists('customer_tags');
    }
};
