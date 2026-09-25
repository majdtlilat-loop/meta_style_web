<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 32)->unique();
            $table->uuid('tenant_id');
            $table->string('subject');
            $table->string('status', 24)->index();
            $table->string('priority', 16)->index();
            $table->string('created_by_type', 24);
            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190);
            $table->foreignId('assigned_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamp('last_activity_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'last_activity_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::connection($this->connection)->create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 24);
            $table->string('author_id', 64)->nullable();
            $table->string('author_label', 190);
            $table->boolean('is_internal')->default(false)->index();
            $table->text('body');
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 96);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('support_ticket_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('actor_type', 24);
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['ticket_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('support_ticket_history');
        Schema::connection($this->connection)->dropIfExists('support_ticket_attachments');
        Schema::connection($this->connection)->dropIfExists('support_ticket_messages');
        Schema::connection($this->connection)->dropIfExists('support_tickets');
    }
};
