<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches each projected center user may work in (none when they have
 * every branch). A row per user and branch so the directory can filter by
 * branch with an ordinary join — no JSON search.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('center_user_directory_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('center_user_directory', indexName: 'cud_branches_entry_foreign')->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->unsignedBigInteger('branch_id');
            $table->json('branch_name')->nullable();

            $table->unique(['entry_id', 'branch_id'], 'cud_branches_entry_branch_unique');
            $table->index(['tenant_id', 'branch_id'], 'cud_branches_tenant_branch_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('center_user_directory_branches');
    }
};
