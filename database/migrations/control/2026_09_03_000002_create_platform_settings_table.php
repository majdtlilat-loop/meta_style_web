<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform behaviour that Super Admin controls without a deploy.
 *
 * Default trial length lives here, not in config and not in business logic:
 * changing "14 days" to "21 days" is a business decision, and requiring a
 * release for it is how hardcoded `14` ends up scattered through the codebase
 * (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §9).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_settings');
    }
};
