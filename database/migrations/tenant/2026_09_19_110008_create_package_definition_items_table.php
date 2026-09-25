<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a package covers: a service — optionally one variation of it — and how
 * many times.
 *
 * A NULL variation covers any variation of the service. Services are archived,
 * never deleted, so the reference is restricted rather than cascaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_definition_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('package_definition_id')->constrained('package_definitions')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('service_variation_id')->nullable()->constrained('service_variations')->restrictOnDelete();

            $table->unsignedSmallInteger('quantity');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_definition_items');
    }
};
