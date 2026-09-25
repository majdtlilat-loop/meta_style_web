<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan carries a monthly AND a yearly price in its own currency.
 *
 * Expand-only: the legacy `price_minor` / `billing_period` stay as the plan's
 * primary price. No backfill — Plan::priceFor() reads a legacy plan's single
 * price for its own billing period until the plan is next saved.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('monthly_price_minor')->nullable()->after('price_minor');
            $table->unsignedBigInteger('yearly_price_minor')->nullable()->after('monthly_price_minor');
            $table->boolean('is_featured')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('plans', fn (Blueprint $table) => $table->dropColumn(['monthly_price_minor', 'yearly_price_minor', 'is_featured']));
    }
};
