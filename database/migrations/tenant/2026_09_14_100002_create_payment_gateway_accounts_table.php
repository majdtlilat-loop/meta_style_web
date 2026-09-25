<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A center's own merchant account at a payment provider, for one branch.
 *
 * ## The center owns the merchant account
 *
 * Customer money settles directly into the center's account at the provider.
 * Meta Style never receives, pools or pays out funds, and never becomes the
 * merchant of record (docs/19-PAYMENTS.md §20, docs/08-AUDIT-SECURITY.md §9).
 * So the credentials here are the CENTER's, in the center's own database.
 *
 * ## Credentials
 *
 * Encrypted with the application key (Laravel's `encrypted:array` cast), never
 * hashed — they must be sent to the provider again. Never returned by any API,
 * Livewire property or log. Disabling an account keeps them: a payment already
 * in flight still needs its callback verified.
 *
 * ## No network destination
 *
 * There is no URL column. Where a provider's API lives is fixed per provider and
 * environment in `config/payments.php`, set by the platform operator. A tenant
 * supplies credentials, never an outbound destination — no server-side request
 * forgery by configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table): void {
            $table->id();

            // Public and opaque: it appears in the provider callback URL.
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // fib · zaincash · qi · fastpay — a registry code, never a class name.
            $table->string('provider', 32);

            // sandbox · live
            $table->string('environment', 16);

            $table->string('display_name', 120);

            $table->boolean('enabled')->default(false);

            // `encrypted:array`. TEXT, and so no default (ADR-033).
            $table->text('credentials')->nullable();

            // A non-secret, already-masked hint shown to managers — the tail of
            // a client id, never a secret or a full identifier.
            $table->string('safe_identifier', 64)->nullable();

            $table->dateTime('configured_at')->nullable();
            $table->string('configured_by_id', 64)->nullable();
            $table->string('configured_by_label', 190)->nullable();

            $table->timestamps();

            /*
             * One account per provider per branch — and the lookup every
             * gateway payment at a branch starts from:
             *
             *   SELECT ... FROM payment_gateway_accounts
             *   WHERE branch_id = ? AND provider = ?
             */
            $table->unique(['branch_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
