<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level provider credentials (the OpenAI key), stored encrypted
 * and write-only. Only a four-character hint is ever readable.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->text('credentials')->nullable();
            $table->string('hint', 16)->nullable();
            $table->string('configured_by_label', 190)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 190)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_provider_credentials');
    }
};
