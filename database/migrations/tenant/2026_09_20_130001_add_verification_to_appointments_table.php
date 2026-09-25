<?php

declare(strict_types=1);

use App\Modules\Booking\Domain\BookingReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two new facts about an appointment: what to CALL it, and how to PROVE it.
 *
 * Deliberately different in kind, which is why they are different columns
 * (docs/24-BOOKING-VERIFICATION.md §§1–3):
 *
 *   `reference`                      public, quotable, enumerable, backfilled
 *   `verification_code_digest`       secret, keyed, never backfilled
 *   `verification_code_key_version`  which pepper wrote the digest
 *
 * ## The reference IS backfilled; the code is NOT
 *
 * A reference authenticates nothing, so deriving one for every historical
 * appointment costs nothing and gains a center the ability to talk about old
 * bookings the same way it talks about new ones.
 *
 * A verification code is a capability. Minting one for a booking nobody asked
 * about would create a live secret that was never delivered to anybody — a
 * credential with no owner, sitting in a database. So legacy rows keep
 * `NULL`, verification is simply UNAVAILABLE for them, and a code is issued
 * only when somebody with authority asks (§10).
 *
 * ## Expand only
 *
 * All three columns are nullable and no existing column changes, so this
 * deploys ahead of the code that writes them and an older running instance is
 * unaffected (docs/03-DATABASE-MIGRATIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            /*
             * Nullable AND unique. MySQL and MariaDB both allow any number of
             * NULLs in a unique index, so the backfill below can run after the
             * column exists rather than needing a value at ADD COLUMN time —
             * and a row that somehow escapes the backfill is visible as NULL
             * instead of colliding on an empty string.
             */
            $table->string('reference', 24)->nullable()->after('uuid');

            // 64 hex characters of HMAC-SHA256. char, not string: every value
            // is exactly this wide, and a fixed-width column says so.
            $table->char('verification_code_digest', 64)->nullable()->after('reference');

            // `v1`, `v2`. Short and non-null WHENEVER a digest is present; the
            // pair is written and cleared together (§5).
            $table->string('verification_code_key_version', 16)->nullable()->after('verification_code_digest');

            // When the CURRENT code was minted. Regeneration moves it, which is
            // what tells a manager the old one stopped working and when.
            $table->dateTime('verification_code_issued_at')->nullable()->after('verification_code_key_version');

            /*
             * The lookup a customer starts from: reference first, then an HMAC
             * comparison in PHP against the single row it found.
             *
             * The DIGEST is deliberately NOT indexed. Nothing ever queries by
             * it — searching for a digest would mean somebody already had the
             * code — and an index on a secret is a small invitation to write
             * that query later (§9, docs/13-ROADMAP.md Phase 13 §86).
             */
            $table->unique('reference');
        });

        $this->backfillReferences();
    }

    /**
     * Gives every existing appointment the reference it would have been born
     * with.
     *
     * One UPDATE, no PHP loop: the value is a pure function of the primary key,
     * so the database can compute it for a million rows without any of them
     * travelling. `LPAD` and `CONCAT` exist identically in MariaDB 10.4 and
     * MySQL 8 (docs/13-ROADMAP.md Phase 13 §85).
     *
     * Kept in step with {@see BookingReference::forId()} by
     * `BookingVerificationTest`, which asserts a backfilled row and a freshly
     * booked one are formatted the same way.
     */
    private function backfillReferences(): void
    {
        DB::connection('tenant')
            ->table('appointments')
            ->whereNull('reference')
            ->update(['reference' => DB::raw("CONCAT('B-', LPAD(id, 6, '0'))")]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'reference',
                'verification_code_digest',
                'verification_code_key_version',
                'verification_code_issued_at',
            ]);
        });
    }
};
