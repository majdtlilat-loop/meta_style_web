<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time tokens for staff to set their own first password.
 *
 * The alternative — a manager typing a password on someone else's behalf — is
 * worse than it looks: the manager then knows a credential that authenticates
 * as that person, and every audit entry from that account becomes deniable.
 *
 * Only the SHA-256 hash of the token is stored, so a leak of this table does
 * not yield usable tokens. Tokens expire, are single-use, and their creation,
 * use and revocation are all audited (docs/08-AUDIT-SECURITY.md §14).
 *
 * Phase 3 returns the token to the creating manager to hand over directly.
 * Delivery by SMS or WhatsApp is Phase 13 — standing up a notification
 * provider purely to deliver these would be building a channel before there is
 * anything to say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // SHA-256 of the plaintext. Never the token itself.
            $table->char('token_hash', 64)->unique();

            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_activation_tokens');
    }
};
