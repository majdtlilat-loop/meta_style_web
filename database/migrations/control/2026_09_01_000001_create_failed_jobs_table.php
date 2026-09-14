<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed queue jobs live in the CONTROL database, not per tenant.
 *
 * One table means operators have one place to look when answering "what is
 * failing right now" instead of querying N tenant databases. Job payloads
 * carry only a tenant identifier and record ids — never tenant data — so this
 * creates no isolation problem (docs/DECISIONS.md ADR-015).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('failed_jobs');
    }
};
