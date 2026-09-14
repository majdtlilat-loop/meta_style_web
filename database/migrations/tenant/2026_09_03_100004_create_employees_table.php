<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff business profile — separate from the login identity in `users`.
 *
 * `user_id` is nullable on purpose. A center may want its stylists listed —
 * eventually on the public menu, assigned to branches, later performing
 * services — without giving every one of them system access. Equally, an owner
 * may have a login and perform no services at all, in which case there is a
 * User and no Employee.
 *
 * Explicitly NOT here (later phases): services performed, schedules, shifts,
 * commissions, attendance, payroll, performance, reviews, service journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Nullable link, and nulled rather than cascaded if the login is
            // removed: deleting an account must not erase the person's
            // employment record or orphan future bookings.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // Translatable: employee names appear on the customer-facing menu
            // from Phase 4, and Iraqi centers will want both Arabic and Latin
            // spellings (docs/07-LOCALIZATION.md §3.2).
            $table->json('name');

            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('employee_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branches');
        Schema::dropIfExists('employees');
    }
};
