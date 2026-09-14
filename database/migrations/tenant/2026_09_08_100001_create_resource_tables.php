<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational resources — the chairs, rooms and devices a service consumes.
 *
 * ## Two tables, because centers do not share equipment lists
 *
 * A `ResourceType` is a capacity CLASSIFICATION ("Laser Machine", "Treatment
 * Room"); a `Resource` is a physical thing of that type ("Laser Machine 2").
 * Services are defined against the type, and booking picks a concrete resource
 * — which is why one "Laser Session" serves a center with one machine and a
 * center with six, without duplicating the service
 * (docs/13-ROADMAP.md Phase 7 §§2, 5).
 *
 * A PHP enum was the alternative and it is wrong here: a barbershop, a laser
 * clinic and a hammam have nothing in common in this list, and a release would
 * be required every time one of them bought a different machine.
 *
 * ## What these tables are NOT
 *
 * Not inventory: no stock levels, no serial numbers, no purchase dates, no
 * maintenance schedule. Not a hierarchy: no parent type, no nesting. A resource
 * answers exactly one question — how many of these can be in use at once
 * (Phase 7 §3).
 *
 * ## Capacity
 *
 * `capacity` is how many simultaneous uses the resource supports. A private
 * treatment room is 1; a shared hammam that seats four is 4. Assuming every
 * resource is exclusive would make a shared space unbookable by more than one
 * person, which is the opposite of what it is for (§4).
 *
 * The floor of 1 is enforced in the Action rather than by a CHECK constraint:
 * Laravel has no portable check builder and the schema has to build identically
 * on MariaDB 10.4 and MySQL 8 (ADR-033).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable, like every other name a customer or a member of
            // staff reads (docs/07-LOCALIZATION.md).
            $table->json('name');
            $table->json('description')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Archived, never deleted: appointments reference the resources of
            // this type and a hard delete would take their history with it
            // (CLAUDE.md, Phase 7 §36).
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // restrictOnDelete on both: a type or a branch cannot be deleted
            // out from under a resource that reservations point at.
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();

            /*
             * NOT NULL, deliberately. A resource is a physical thing standing
             * in one place, and a nullable branch would make "is this chair
             * free?" ambiguous in the conflict query — worse, it would break
             * the branch-row lock, which can only serialise contention for
             * resources that belong to the branch being locked (§7, ADR-044).
             *
             * A center with a genuinely shared asset models one resource per
             * branch.
             */
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // Optional, and the operational axis — Department, never menu
            // Category (ADR-037). Room 3 belongs to the Laser department;
            // "Hair Removal" is a menu grouping and routes nothing.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->json('name');

            /*
             * Simultaneous uses. See the class note above; 1 is exclusive.
             */
            $table->unsignedSmallInteger('capacity')->default(1);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            // "Which resources can serve a booking at this branch?" — the
            // allocator's first question, on every candidate slot.
            $table->index(['branch_id', 'is_active']);

            // "Which resources satisfy this requirement type, here?" — the
            // second, once a service's requirements are known.
            $table->index(['resource_type_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
        Schema::dropIfExists('resource_types');
    }
};
