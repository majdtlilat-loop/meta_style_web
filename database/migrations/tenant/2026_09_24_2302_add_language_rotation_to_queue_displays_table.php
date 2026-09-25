<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A screen may cycle its OWN labels through several languages — EN, then AR,
 * then KU, then EN again — every few seconds (docs/17-QUEUE.md §9).
 *
 *   rotation_enabled   off by default: today's screens keep one language
 *   rotation_locales   the languages to cycle, e.g. ["en","ar","ckb"]. JSON
 *                      because it is an ordered list read as a whole and never
 *                      queried (ADR-033). Intersected with the CENTER's enabled
 *                      languages on every read, so switching a language off
 *                      for the center removes it from every screen without
 *                      editing them — and disabling never deletes this list.
 *   rotation_seconds   seconds per language; clamped 5–60 by the model
 *
 * Presentation only. The ticket voice keeps its own `voice_locales`; the
 * screen's language never decides what is spoken or when (§16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_displays', function (Blueprint $table): void {
            $table->boolean('rotation_enabled')->default(false)->after('promo_slide_seconds');
            $table->json('rotation_locales')->nullable()->after('rotation_enabled');
            $table->unsignedTinyInteger('rotation_seconds')->default(10)->after('rotation_locales');
        });
    }

    public function down(): void
    {
        Schema::table('queue_displays', function (Blueprint $table): void {
            $table->dropColumn(['rotation_enabled', 'rotation_locales', 'rotation_seconds']);
        });
    }
};
