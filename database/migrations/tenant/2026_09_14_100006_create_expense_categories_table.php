<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center's own words for where its money goes.
 *
 * Center-defined and translated. There is no enum of "rent, salaries, supplies":
 * a spa and a barbershop do not share a chart of expenses, and hardcoding one
 * would be accounting policy nobody asked for (docs/20-FINANCE.md §38).
 *
 * Archived, never deleted: posted expenses keep pointing at their category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable JSON; no default on JSON (ADR-033).
            $table->json('name');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
