<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropForeign(['conversation_id']);
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_id')->nullable()->change();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->string('source', 48)->default('conversation')->after('conversation_id');
            $table->string('report_code', 64)->nullable()->after('source');
            $table->foreignId('requested_by_user_id')->nullable()->after('report_code')->constrained('users')->nullOnDelete();
            $table->index(['source', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['source', 'started_at']);
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['source', 'report_code']);
            $table->dropForeign(['conversation_id']);
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
        });

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
        });
    }
};
