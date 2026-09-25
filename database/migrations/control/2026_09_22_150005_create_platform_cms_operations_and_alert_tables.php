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
        Schema::connection($this->connection)->create('landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 96)->unique();
            $table->json('title');
            $table->json('draft_content');
            $table->json('published_content')->nullable();
            $table->unsignedInteger('draft_version')->default(1);
            $table->unsignedInteger('published_version')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('updated_by_id', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('landing_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content');
            $table->string('created_by_id', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['landing_page_id', 'version']);
        });

        Schema::connection($this->connection)->create('tenant_operational_projections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->unique();
            $table->string('health', 24)->default('unknown')->index();
            $table->string('provisioning_status', 32);
            $table->string('migration_status', 32);
            $table->string('schema_version', 191)->nullable();
            $table->unsignedInteger('open_support_tickets')->default(0);
            $table->unsignedInteger('active_alerts')->default(0);
            $table->timestamp('last_operation_at')->nullable();
            $table->timestamp('projected_at')->useCurrent();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::connection($this->connection)->create('platform_alerts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('severity', 16)->index();
            $table->string('source', 64)->index();
            $table->json('title');
            $table->json('body');
            $table->string('action_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        Schema::connection($this->connection)->create('platform_alert_reads', function (Blueprint $table): void {
            $table->foreignId('alert_id')->constrained('platform_alerts')->cascadeOnDelete();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['alert_id', 'platform_user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_alert_reads');
        Schema::connection($this->connection)->dropIfExists('platform_alerts');
        Schema::connection($this->connection)->dropIfExists('tenant_operational_projections');
        Schema::connection($this->connection)->dropIfExists('landing_page_revisions');
        Schema::connection($this->connection)->dropIfExists('landing_pages');
    }
};
