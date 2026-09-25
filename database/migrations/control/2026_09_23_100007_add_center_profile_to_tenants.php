<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A center's operational currency, contact person and timezone, as the
 * Super Admin sets them. All nullable: an existing center keeps behaving as
 * it does today. Expand-only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('tenants', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable()->after('slug');
            $table->string('contact_name', 190)->nullable()->after('currency');
            $table->string('contact_email', 190)->nullable()->after('contact_name');
            $table->string('contact_phone', 48)->nullable()->after('contact_email');
            $table->string('timezone', 64)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tenants', fn (Blueprint $table) => $table->dropColumn(['currency', 'contact_name', 'contact_email', 'contact_phone', 'timezone']));
    }
};
