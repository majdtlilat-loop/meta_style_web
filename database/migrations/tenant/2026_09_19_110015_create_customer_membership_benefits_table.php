<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer membership's benefits, copied from the plan at activation with the
 * service's name — so editing the plan changes nothing somebody already bought.
 *
 * `uses_limit` is the plan's `uses_per_term` as it was; what is used is proven
 * from `membership_benefit_usages`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_membership_benefits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_membership_id')->constrained('customer_memberships')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->json('service_name')->nullable();

            $table->string('discount_type', 16);
            $table->unsignedSmallInteger('basis_points')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedSmallInteger('uses_limit')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_benefits');
    }
};
