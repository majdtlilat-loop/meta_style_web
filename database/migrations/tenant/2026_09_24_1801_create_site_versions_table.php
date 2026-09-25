<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center's public landing page — drafted, published, archived.
 *
 * The same shape as `menu_versions` and for the same reason: draft, live and
 * history are one document in three states, so publishing is a status change
 * rather than a copy between schemas.
 *
 *   draft      at most one. What the owner is editing. Never public.
 *   published  at most one. What the center's `/` page renders.
 *   archived   every previously published version, newest first. Restoring
 *              one copies it FORWARD into the draft; history never moves.
 *
 * `content` is JSON because its shape is a code-owned catalog
 * (CenterSite\Domain\SiteCatalog) validated on every write by the SiteContent
 * allow-list normalizer: plain text only, validated https links, media by
 * uuid, presentation choices from fixed lists. No column accepts HTML, CSS or
 * script — a center-authored script on a guest page is stored XSS against the
 * center's own customers (ADR-038).
 *
 * This is a separate table from the platform's corporate `landing_pages`
 * (control plane): a center's site lives in the center's own database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('status', 16);

            // Monotonic per center. Human-facing: "restore version 4".
            $table->unsignedInteger('version');

            $table->json('content');

            // Which archived version a draft was copied from, when it was.
            $table->unsignedInteger('restored_from_version')->nullable();

            // Who last saved the draft, and who published it.
            $table->foreignId('saved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique('version');
            $table->index(['status', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_versions');
    }
};
