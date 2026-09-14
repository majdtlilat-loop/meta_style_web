<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The letter a department's queue numbers carry: Laser → L001, Hair → H001.
 *
 * Department is the operational routing axis (ADR-037), so it is also the axis
 * a center thinks in when it separates its queues. A single-department
 * barbershop leaves this null, gets `A001`, and never sees the feature
 * (docs/17-QUEUE.md §6).
 *
 * Four characters, because it is read aloud, printed on 80mm paper and shown on
 * a television. Validation — uppercase ASCII letters and digits only, no
 * whitespace — lives in the Action, where a useful error message can be
 * produced; the column only bounds the damage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('queue_prefix', 4)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('queue_prefix');
        });
    }
};
