<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the Phase 3 placeholder branch into a real operational record.
 *
 * Expand-only: every column is nullable or defaulted, so the release that adds
 * them runs against tenants still serving traffic on the old code
 * (docs/03-DATABASE-MIGRATIONS.md §2).
 *
 * Still no `tenant_id`. Branch ownership is implicit in which database the row
 * lives in, and a redundant column would be a second source of truth and an
 * invitation to filter by it instead of by connection (docs/02-TENANCY.md §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            // Contact details a customer needs from the public menu. Phone and
            // WhatsApp are separate because in Iraq they frequently are: a
            // landline for the shop, a mobile for bookings.
            $table->string('phone', 32)->nullable()->after('timezone');
            $table->string('whatsapp', 32)->nullable()->after('phone');
            $table->string('email')->nullable()->after('whatsapp');

            // Translatable: a center in Baghdad writes its address in Arabic
            // and, for a tourist-facing menu, in English too.
            $table->json('address')->nullable()->after('email');

            $table->string('map_url', 512)->nullable()->after('address');

            // DECIMAL, not float. Coordinates are not money, but they are still
            // a value where "close enough" silently drifts: a float latitude
            // moves a pin by metres per round trip. 7 decimal places is ~1cm,
            // far beyond what a shop pin needs, and it is exact on both
            // MariaDB and MySQL 8 (ADR-033).
            $table->decimal('latitude', 10, 7)->nullable()->after('map_url');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Distinct from is_active. A branch can be operating (staff work
            // there, bookings exist) without appearing on the public menu — a
            // staff-only back office, or one not yet announced.
            $table->boolean('is_public')->default(true)->after('is_active');

            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_public');

            // Archive rather than delete: employees, and later bookings and
            // invoices, reference branches (docs/13-ROADMAP.md Phase 4 §20).
            $table->timestamp('archived_at')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn([
                'phone', 'whatsapp', 'email', 'address', 'map_url',
                'latitude', 'longitude', 'is_public', 'sort_order', 'archived_at',
            ]);
        });
    }
};
