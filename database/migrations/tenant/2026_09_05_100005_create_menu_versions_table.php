<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a center's public menu looks — drafted, published, and rolled back.
 *
 * ONE TABLE does draft, live and history, because they are the same document in
 * three states. A separate `menu_drafts` table would duplicate every column and
 * make "publish" a copy between schemas rather than a status change.
 *
 *   draft      exactly one. What the owner is editing. Invisible to customers.
 *   published  exactly one. What customers see right now.
 *   archived   every previously published version, newest first. Rollback
 *              republishes one of these.
 *
 * `theme` and `sections` are JSON because their shape is a code-owned catalog
 * that will grow — a column per setting would mean a migration across every
 * tenant database each time a template gains an option.
 *
 * THIS IS CONFIGURATION, NOT A PAGE BUILDER. Both JSON columns are validated
 * against `config/menu.php` on write: known section keys, known template keys,
 * colours matching a hex pattern, fonts from an approved list. There is no
 * field anywhere that accepts HTML, CSS or JavaScript, and that is a security
 * property, not a scope decision — a center-authored `<script>` on a
 * guest-accessible page is stored XSS against that center's own customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('status', 16)->index();

            // Monotonic per tenant. Human-facing: "restore version 4".
            $table->unsignedInteger('version');

            $table->string('template_key', 64);
            $table->json('theme');
            $table->json('sections');

            // Who published it, for the audit trail. Nullable because the first
            // version is seeded by provisioning, with no human involved.
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique('version');
            $table->index(['status', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_versions');
    }
};
