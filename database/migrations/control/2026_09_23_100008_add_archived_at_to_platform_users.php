<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform users are archived, never deleted, so support tickets, audit
 * entries and billing records keep resolving to a name.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('platform_users', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('platform_users', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
