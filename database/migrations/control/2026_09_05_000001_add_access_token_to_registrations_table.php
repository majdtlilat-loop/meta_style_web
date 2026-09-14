<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates the registration's public LOCATOR from its secret CAPABILITY.
 *
 * Until now the uuid was both: anyone holding it could read a registration's
 * status and trigger a retry. A uuid is not a secret — it travels in a redirect
 * URL, browser history, a support ticket, a screenshot — so the capability has
 * to be a separate high-entropy value (ADR-035).
 *
 * `access_token_hash` is SHA-256 of a 256-bit random token. Plaintext is
 * returned to the registering client once and never persisted.
 *
 * `access_expires_at` is set when the registration reaches `ready`, granting a
 * short READ-ONLY grace so the client's next poll can still learn its center
 * key. Retry is not gated by this column at all — it requires status `failed`,
 * which `ready` can never return to.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            // 64 hex characters. Not encrypted: a SHA-256 digest of a 256-bit
            // random value is not reversible, and the column must stay
            // searchable for a constant-time lookup by uuid + digest.
            $table->char('access_token_hash', 64)->nullable()->after('owner_password_hash');

            $table->timestamp('access_expires_at')->nullable()->index()->after('credentials_expire_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->dropIndex(['access_expires_at']);
            $table->dropColumn(['access_token_hash', 'access_expires_at']);
        });
    }
};
