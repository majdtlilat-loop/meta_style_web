<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who issued a SaaS invoice (company name, address, tax number, contacts) as it
 * stood when the invoice was issued, so a later change to the billing identity
 * never rewrites an old invoice. Nullable: invoices issued before this column
 * existed render with the current identity. Expand-only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->table('saas_invoices', function (Blueprint $table): void {
            $table->json('issuer_snapshot')->nullable()->after('plan_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('saas_invoices', fn (Blueprint $table) => $table->dropColumn('issuer_snapshot'));
    }
};
