<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use SensitiveParameter;

/**
 * A freshly generated verification code: the raw value, and the two columns
 * that record it.
 *
 * Exists so that generating a code and PERSISTING one are visibly the same
 * step. An Action that received only a digest would have no way to hand the
 * customer their code, and an Action that received only a raw string would have
 * to remember to hash it with the right key — this carries all three together
 * so neither mistake has a place to happen
 * (docs/24-BOOKING-VERIFICATION.md §3).
 *
 * `$raw` is the only copy that will ever exist. It is returned to exactly one
 * caller, and the moment that call returns it is gone: nothing writes it to a
 * column, a log, an audit row, a notification payload or a conversation
 * message (§11).
 */
final readonly class MintedCode
{
    public function __construct(
        #[SensitiveParameter]
        public string $raw,
        public string $digest,
        public string $keyVersion,
    ) {}

    /**
     * The two columns, ready to merge into an insert or an update.
     *
     * @return array{verification_code_digest: string, verification_code_key_version: string}
     */
    public function columns(): array
    {
        return [
            'verification_code_digest' => $this->digest,
            'verification_code_key_version' => $this->keyVersion,
        ];
    }

    /**
     * Keeps a stray `dump()` or a serialised exception from printing the one
     * copy of the secret.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['raw' => '[redacted]', 'digest' => $this->digest, 'keyVersion' => $this->keyVersion];
    }
}
