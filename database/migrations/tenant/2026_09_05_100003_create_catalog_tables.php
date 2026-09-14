<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments, categories, services — the operating catalog of a center.
 *
 * DEPARTMENT vs CATEGORY. These look similar and are not the same thing, and
 * conflating them is a mistake that gets expensive later:
 *
 *   Department  OPERATIONAL. Hair, Laser, Hammam, Nails. Later drives the
 *               service journey, employee assignment, queue routing, rooms and
 *               reporting. Internal.
 *   Category    CUSTOMER-FACING. How the electronic menu is organised. A center
 *               may put "Bridal Package" services from three departments into
 *               one menu category, and that is a presentation decision.
 *
 * A service has both, independently. One table with an `is_operational` flag
 * would force every future query to remember the flag.
 *
 * ARCHIVING, not deletion. A service will be referenced by appointments and
 * invoices from Phase 6 onward; a hard delete would orphan history that legally
 * has to stay readable. `archived_at` is the lifecycle now so nothing has to be
 * retrofitted onto populated tables later.
 *
 * NO JSON functional indexes anywhere here. The translatable columns are
 * ordinary JSON, ordered and filtered by plain scalar columns beside them
 * (ADR-033).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->json('name');
            $table->json('description')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->json('name');
            $table->json('description')->nullable();

            $table->boolean('is_active')->default(true)->index();

            // Separate from is_active: a category can exist and be usable
            // internally while being held back from the public menu.
            $table->boolean('is_public')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Both nullable and both singular. A service without a department
            // is still a service — a center that has not organised itself yet
            // must not be blocked from adding one. Single-valued because
            // many-to-many here buys nothing a center asked for and makes
            // every menu query a join with ordering ambiguity (ADR-037).
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();

            $table->json('name');
            $table->json('short_description')->nullable();
            $table->json('description')->nullable();

            // Minutes, integer. Not a duration string, not seconds: every
            // booking UI in this domain works in minutes.
            $table->unsignedSmallInteger('duration_minutes');

            // INTEGER MINOR UNITS. The currency is a center-level setting, not
            // a column here: one center, one price list, one currency. A
            // per-row currency would permit a menu that mixes IQD and USD,
            // which is a bug, not a feature (docs/10-API-FOUNDATION.md §9).
            $table->unsignedBigInteger('price_minor')->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true);

            // Distinct from is_public: a service can be listed on the menu for
            // information while requiring a phone call to book. Booking does
            // not exist yet; this flag records the center's intent so the
            // Booking phase has it rather than asking every center again.
            $table->boolean('is_online_bookable')->default(true);

            // "All branches" is the common case and needs no rows. Only a
            // service restricted to some branches gets pivot rows.
            $table->boolean('available_at_all_branches')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('service_variations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->json('name');

            // NULL means "inherit from the service" — not "zero", and not a
            // copy taken at creation time. A center that raises the base price
            // of a haircut expects the variations that never had their own
            // price to follow (ADR-037).
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'is_active']);
        });

        Schema::create('service_addons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->json('name');

            // Add-ons always carry their own price and duration: an add-on with
            // no price of its own is not an add-on.
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Shared, not owned. "Hair Wash" applies to five services; owning it
        // per service would mean five rows to edit when its price changes, and
        // five chances to miss one.
        Schema::create('service_addon_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('service_addon_id')->constrained('service_addons')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['service_id', 'service_addon_id']);
        });

        Schema::create('branch_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->unique(['branch_id', 'service_id']);
        });

        // ELIGIBILITY ONLY. Who *may* perform this service. Not a schedule, not
        // availability, not a commission rate — the Booking Engine will combine
        // this with schedules and existing bookings when it exists.
        Schema::create('employee_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->unique(['employee_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service');
        Schema::dropIfExists('branch_service');
        Schema::dropIfExists('service_addon_service');
        Schema::dropIfExists('service_addons');
        Schema::dropIfExists('service_variations');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('departments');
    }
};
