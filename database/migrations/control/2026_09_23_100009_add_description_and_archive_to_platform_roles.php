<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom platform roles get a translatable description and are archived
 * before they can be deleted.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('platform_roles', function (Blueprint $table): void {
            $table->json('description')->nullable()->after('name');
            $table->timestamp('archived_at')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('platform_roles', fn (Blueprint $table) => $table->dropColumn(['description', 'archived_at']));
    }
};
