<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The promotional images and videos ONE screen plays beside the queue.
 *
 * The file itself is an ordinary `media_items` row (owner `queue_display`,
 * public `branding` collection), stored and validated on its bytes by the
 * media kernel — never a URL, never markup, never SVG. This table says how the
 * screen uses it:
 *
 *   sort_order  the play order, renumbered 0..n-1 under a lock
 *   is_enabled  paused items stay in the list without playing
 *   caption     optional short line shown over the item, one per content
 *               language (Translatable JSON — no per-language columns)
 *
 * One row per media item (unique), and the row goes when its file goes
 * (cascade from `media_items`). Displays are archived, never deleted, so the
 * display FK restricts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_display_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('queue_display_id')->constrained('queue_displays')->restrictOnDelete();
            $table->foreignId('media_item_id')->unique()->constrained('media_items')->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->json('caption')->nullable();

            $table->timestamps();

            // The only read: one screen's items in play order.
            $table->index(['queue_display_id', 'sort_order'], 'qd_media_display_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_display_media');
    }
};
