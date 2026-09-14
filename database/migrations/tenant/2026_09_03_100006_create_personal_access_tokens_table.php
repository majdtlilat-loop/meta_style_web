<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum tokens, stored in the TENANT database.
 *
 * This placement is the primary tenant binding, and it is structural rather
 * than a check somebody has to remember: a token issued by Tenant A simply does
 * not exist in Tenant B's database, so presenting it there cannot authenticate
 * anything. There is no shared token table to get the scoping wrong in.
 *
 * A second, independent layer sits in front of it: the issued token string
 * carries the tenant's public key, which lets a mismatch between the host and
 * the token be detected and audited as a security event rather than surfacing
 * as a bare 401 (docs/DECISIONS.md ADR-027).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
