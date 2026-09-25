<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer's package covers — copied from the definition at activation,
 * with names, so a renamed service or an edited definition changes nothing the
 * customer paid for.
 *
 * `quantity` is what was ALLOCATED. What is left is proven from the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_package_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_package_id')->constrained('customer_packages')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('service_variation_id')->nullable()->constrained('service_variations')->restrictOnDelete();

            $table->json('name');
            $table->json('variation_name')->nullable();

            $table->unsignedSmallInteger('quantity');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_package_items');
    }
};
