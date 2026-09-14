<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for mutating requests (docs/10-API-FOUNDATION.md §6).
 *
 * Booking creation is the first operation in Meta Style where a duplicate is
 * genuinely expensive: two appointments for one customer at one time, holding
 * two slots, generating two reminders and later two invoices. And duplicates
 * are not hypothetical — a phone on a poor connection retries, a customer taps
 * "confirm" twice on a slow page, WhatsApp delivers a webhook at least once,
 * and RAYAN retries on timeout.
 *
 * ONE TABLE FOR EVERY CHANNEL, not one per channel. `Kernel\Http\Idempotency`
 * is the only thing that reads or writes it; the API middleware and the public
 * booking form both go through that service (§29).
 *
 * In the TENANT database on purpose. A key is scoped to a center, so two
 * centers cannot collide, and a center's replay history is deleted with the
 * center.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();

            // Client-supplied. Opaque, and never trusted for anything except
            // matching one request to its own earlier attempt.
            $table->string('key', 190);

            // Scoped by endpoint so the same key reused against a different
            // operation is a different record rather than a false replay.
            $table->string('endpoint', 190);

            // sha256 of the canonicalised payload. Same key + same hash replays
            // the stored response; same key + DIFFERENT hash is a client bug or
            // an attack and is refused with IDEMPOTENCY.CONFLICT.
            $table->char('request_hash', 64);

            $table->string('status', 16)->default('in_progress');
            $table->unsignedSmallInteger('response_code')->nullable();

            // The response body, replayed verbatim. Bodies here are booking
            // confirmations: uuids, times, prices. No credential and no token
            // is ever returned by an endpoint this protects.
            $table->longText('response_body')->nullable();

            $table->dateTime('locked_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            // The lookup, and the guarantee: one row per key per endpoint, so
            // two concurrent retries cannot both create one. The unique
            // violation IS the concurrency control.
            $table->unique(['endpoint', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
