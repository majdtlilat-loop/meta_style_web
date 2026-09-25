<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service packages the center sells: "10 haircuts", "6 laser sessions".
 *
 * A definition is what is OFFERED. A customer's package is a snapshot of it at
 * the moment of sale, so editing or archiving a definition never changes what
 * somebody already paid for (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §13).
 *
 *   price_minor     what the package line is priced at on the till
 *   validity_days   whole branch-local calendar days, counting the day it
 *                   activates
 *
 * Archived, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable JSON; no default on JSON (ADR-033).
            $table->json('name');

            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('validity_days');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_definitions');
    }
};
