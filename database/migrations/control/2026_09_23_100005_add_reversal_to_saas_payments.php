<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recorded settlement is corrected by reversing it, never by deleting
 * or editing it: who reversed it, when and why. Expand-only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('saas_payments', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('received_at');
            $table->string('reversed_by_id', 64)->nullable()->after('reversed_at');
            $table->string('reversed_by_label', 190)->nullable()->after('reversed_by_id');
            $table->text('reversal_reason')->nullable()->after('reversed_by_label');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('saas_payments', fn (Blueprint $table) => $table->dropColumn(['reversed_at', 'reversed_by_id', 'reversed_by_label', 'reversal_reason']));
    }
};
