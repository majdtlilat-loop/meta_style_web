<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

use SensitiveParameter;

/**
 * An untrusted HTTP request from a provider, before anything has been believed.
 *
 * THE RAW BODY IS KEPT AS A STRING, not as a decoded array, and that is load
 * bearing. A signature is computed over the exact bytes the provider sent;
 * decoding and re-encoding JSON reorders keys, changes number formatting and
 * normalises unicode escapes, and the signature over the result would never
 * match. Every "the signature verification does not work" bug in this shape of
 * integration is this (docs/25-WHATSAPP.md §8).
 *
 * Nothing in here is trusted until an adapter has verified it. Not the sender,
 * not the account, not the message id.
 */
final readonly class InboundEnvelope
{
    /**
     * @param  array<string, string>  $headers  lower-cased header names
     * @param  array<string, string>  $query
     */
    public function __construct(
        #[SensitiveParameter]
        public string $rawBody,
        public array $headers,
        public array $query = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    /**
     * One query parameter, by the name the PROVIDER uses.
     *
     * PHP rewrites dots in query-string keys to underscores before any
     * framework sees them, so Meta's `hub.mode`, `hub.verify_token` and
     * `hub.challenge` reach the controller as `hub_mode`, `hub_verify_token`
     * and `hub_challenge`. Asking only for the dotted name made the
     * registration handshake impossible over real HTTP. Both spellings name
     * the same parameter; what the adapter then checks is unchanged.
     */
    public function queryValue(string $name): ?string
    {
        return $this->query[$name] ?? $this->query[str_replace('.', '_', $name)] ?? null;
    }

    /**
     * The decoded body, or an empty array when it is not usable JSON.
     *
     * For PARSING only, never for verification — {@see $rawBody} is what a
     * signature is checked against.
     *
     * @return array<string, mixed>
     */
    public function decoded(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        // A provider body carries a customer's phone number, their profile name
        // and what they said. None of that belongs in a stack trace.
        return ['rawBody' => '[redacted]', 'headers' => array_keys($this->headers)];
    }
}
