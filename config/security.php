<?php

/*
|--------------------------------------------------------------------------
| Application secrets that are not the application key
|--------------------------------------------------------------------------
|
| docs/24-BOOKING-VERIFICATION.md §§4–6.
|
| Keyed HMAC peppers, resolved BY VERSION. Every digest written with one of
| these records which version produced it, so a key can be rotated without a
| rehash — which matters because the values these peppers protect are SHORT
| CODES a person can type, and the raw input does not exist anywhere to rehash
| from.
|
| DELIBERATELY NOT `APP_KEY`. The application key encrypts recoverable data —
| gateway credentials, bootstrap passwords — and is rotated with a re-encryption
| pass over that data. A pepper protects values that can never be re-derived, so
| rotating it has to mean "start writing v2, keep verifying v1", which is a
| different lifecycle and therefore a different secret (ADR-069).
|
| NEVER stored in a tenant database. Never logged. Never audited. A key present
| here and absent in production is a deployment that silently cannot verify
| anything it wrote — which is why `metastyle:doctor --production` fails closed
| on every one of the rules below.
|
*/

return [

    /*
     * Each entry is one named pepper with an ACTIVE version and the versions
     * still needed to verify existing digests.
     *
     * Rotation, in order (docs/24-BOOKING-VERIFICATION.md §6):
     *
     *   1. add `v2` to `versions`, leaving `active` on `v1`
     *   2. move `active` to `v2` — new and regenerated codes use it
     *   3. keep `v1` for as long as codes written with it may still be used
     *   4. remove `v1` only when those codes are intentionally being retired;
     *      every one of them stops verifying at that moment, by design
     */
    'keys' => [

        'booking_verification' => [
            'active' => env('BOOKING_VERIFICATION_ACTIVE_KEY', 'v1'),

            'versions' => array_filter([
                'v1' => env('BOOKING_VERIFICATION_KEY_V1'),
                'v2' => env('BOOKING_VERIFICATION_KEY_V2'),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
        ],

    ],

    /*
     * The shortest key that may be used in production, in BYTES of decoded
     * material.
     *
     * 32 bytes = 256 bits, matching the digest it feeds. Shorter is refused by
     * the doctor rather than accepted quietly, because a sixteen-character
     * passphrase in a `_KEY_V1` variable looks exactly like a real key in every
     * deployment listing that will ever be read.
     */
    'minimum_key_bytes' => 32,

];
