<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A center can be created by its owner (self-registration) or by a Super
 * Admin. Both go through the SAME provisioning pipeline; the registration
 * records which one, and the few commercial choices a Super Admin made up
 * front (billing cycle, currency, languages, trial), so the pipeline can
 * apply them once the center exists. Expand-only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->string('source', 16)->default('self')->after('status');
            $table->json('options')->nullable()->after('selected_plan_id');
            $table->string('created_by_label', 190)->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('registrations', function (Blueprint $table): void {
            $table->dropColumn(['source', 'options', 'created_by_label']);
        });
    }
};
