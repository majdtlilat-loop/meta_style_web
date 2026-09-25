<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements the Super Admin sends to centers, in every platform
 * language. Delivery writes into each center's own in-app inbox.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_announcements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->json('title');
            $table->json('body');
            $table->string('severity', 16);
            $table->string('audience', 16);
            $table->json('tenant_ids')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->unsignedInteger('centers_count')->default(0);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('created_by_id', 64);
            $table->string('created_by_label', 190);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_announcements');
    }
};
