<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a registration an explicit retry window.
 *
 * Phase 3 cleared the bootstrap credential on failure as well as on success,
 * which made a failed self-registration permanently unrecoverable — the owner
 * had to sign up again. That is not acceptable for production
 * (docs/DECISIONS.md ADR-031).
 *
 * The credential now survives a failure, but only until this timestamp. After
 * it, the registration is swept: the credential is destroyed and the row is
 * marked abandoned. A password hash kept indefinitely "just in case" is a
 * liability, not a convenience.
 *
 * Expand-only: nullable column, no data rewritten.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            // Indexed because the sweep queries it on a schedule.
            $table->timestamp('credentials_expire_at')->nullable()->after('owner_password_hash')->index();
            $table->timestamp('settled_at')->nullable()->after('completed_at');
        });

        // Existing rows predate the window. They already had their credential
        // cleared by the old behaviour, so there is nothing to protect and
        // nothing to expire.
        DB::connection($this->connection)
            ->table('registrations')
            ->whereNull('credentials_expire_at')
            ->whereNotNull('completed_at')
            ->update(['settled_at' => DB::raw('completed_at')]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->dropIndex(['credentials_expire_at']);
            $table->dropColumn(['credentials_expire_at', 'settled_at']);
        });
    }
};
