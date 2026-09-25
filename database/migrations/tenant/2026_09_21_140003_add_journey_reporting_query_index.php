<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_journeys', function (Blueprint $table): void {
            $table->index(['arrived_at', 'appointment_id'], 'journeys_arrived_appointment_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_journeys', fn (Blueprint $table) => $table->dropIndex('journeys_arrived_appointment_index'));
    }
};
