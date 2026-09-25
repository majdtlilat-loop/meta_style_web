<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A waiting-room screen may also show the center's own promotional images and
 * videos beside the queue (docs/17-QUEUE.md §9).
 *
 * Two settings on the screen that already exists — not a signage product:
 *
 *   promo_enabled        whether this screen shows its media panel at all
 *   promo_slide_seconds  how long one image stays up; clamped 4–60 by the
 *                        model, so a stored 0 cannot spin the carousel
 *
 * Expand only: both columns have defaults that reproduce today's screen (no
 * media), so an older running instance is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_displays', function (Blueprint $table): void {
            $table->boolean('promo_enabled')->default(false)->after('voice_locales');
            $table->unsignedTinyInteger('promo_slide_seconds')->default(8)->after('promo_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('queue_displays', function (Blueprint $table): void {
            $table->dropColumn(['promo_enabled', 'promo_slide_seconds']);
        });
    }
};
