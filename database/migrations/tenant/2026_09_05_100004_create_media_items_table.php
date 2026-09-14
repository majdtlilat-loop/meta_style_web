<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of a stored file. The first real consumer of Phase 2's MediaStore.
 *
 * OWNER IS A SHORT STRING KEY, not a model class name. Laravel's default
 * morph column stores `App\Modules\Catalog\Domain\Models\Service`, which means
 * moving a class between namespaces silently orphans every row that pointed at
 * it — a refactor becomes a data migration across every tenant database. The
 * enum values here (`service`, `branch`, ...) are stable strings that survive
 * any amount of rearranging (docs/09-STORAGE.md §5).
 *
 * `path` is a DISK-RELATIVE path produced by MediaStore, never an absolute
 * filesystem path and never a URL. Tenant isolation comes from the disk being
 * rooted at `tenants/{key}/`; a stored absolute path would escape that the
 * first time it was copied between environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // 'branch' | 'department' | 'service_category' | 'service'
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');

            // Which MediaStore collection — decides the disk and whether the
            // file is publicly readable.
            $table->string('collection', 32);

            $table->string('path', 512);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // Translatable: alt text is read aloud by a screen reader in the
            // reader's language, so it is content, not metadata.
            $table->json('alt_text')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // The lookup every gallery does. Plain scalar columns; no JSON
            // functional index anywhere (ADR-033).
            $table->index(['owner_type', 'owner_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
