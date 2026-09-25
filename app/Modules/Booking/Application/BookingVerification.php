<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Kernel\Security\Keyring;
use App\Modules\Booking\Domain\Data\MintedCode;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\VerificationCode;
use Random\RandomException;
use SensitiveParameter;

/**
 * Mints and checks booking verification codes. The only place either happens.
 *
 * ## Minting
 *
 * {@see mint()} draws a fresh code from the CSPRNG, normalises it, and takes
 * the digest under the keyring's ACTIVE version. It touches no database: the
 * caller — {@see Actions\CreateAppointment} or
 * {@see Actions\IssueVerificationCode} — writes both columns inside its own
 * transaction, so a rolled-back booking leaves no usable code behind and a
 * committed one can never exist without its digest
 * (docs/24-BOOKING-VERIFICATION.md §3).
 *
 * ## Checking
 *
 * {@see matches()} uses the key version STORED ON THE ROW, and no other.
 *
 * Trying every configured key until one matched would look like helpfulness and
 * would be the bug: a key kept only to verify old codes would silently start
 * accepting new ones, a rotation would become unobservable, and removing a
 * retired key would change behaviour nobody could predict. Verification is
 * therefore exactly one HMAC, against exactly one key (§5).
 *
 * A row whose version is not configured raises {@see MissingKeyVersion} rather
 * than answering false — "wrong code" and "this deployment cannot check codes"
 * are different facts, and the public path turns the second into the same
 * generic refusal while also REPORTING it (§7).
 *
 * ## Comparison
 *
 * `hash_equals`, always. A digest comparison with `===` leaks its answer in the
 * time it takes, and while that is a thin channel against a 50-bit code behind
 * a rate limiter, writing the constant-time version costs nothing.
 */
final class BookingVerification
{
    /** The name this module's pepper is registered under in `config/security.php`. */
    public const KEY = 'booking_verification';

    public function __construct(private readonly Keyring $keyring) {}

    /**
     * A new code and the columns that record it.
     *
     * @throws RandomException when the platform has no usable CSPRNG
     * @throws MissingKeyVersion when no active pepper is configured — refusing
     *                           to write a digest nothing could ever verify
     */
    public function mint(): MintedCode
    {
        $raw = VerificationCode::generate();

        $version = $this->keyring->activeVersion(self::KEY);

        return new MintedCode(
            raw: $raw,
            // Over the NORMALISED form, so the digest is taken over exactly
            // what verification will compute from what a customer types.
            digest: $this->keyring->hmac(self::KEY, VerificationCode::normalise($raw), $version),
            keyVersion: $version,
        );
    }

    /**
     * Does this presented code open this appointment?
     *
     * False for a legacy booking that has never been issued one — there is
     * nothing to match, and a NULL digest must never be treated as "matches
     * anything" (§10).
     *
     * @throws MissingKeyVersion when the row names a key this deployment does
     *                           not have
     */
    public function matches(Appointment $appointment, #[SensitiveParameter] string $presented): bool
    {
        $digest = $appointment->verification_code_digest;
        $version = $appointment->verification_code_key_version;

        if ($digest === null || $version === null || $version === '') {
            return false;
        }

        $normalised = VerificationCode::normalise($presented);

        // Shape first, so a malformed guess never reaches the HMAC and cannot
        // be used to time the difference between "no code" and "wrong code".
        if (! VerificationCode::isWellFormed($normalised)) {
            return false;
        }

        return hash_equals($digest, $this->keyring->hmac(self::KEY, $normalised, $version));
    }

    /**
     * Whether this appointment's code could be checked at all right now.
     *
     * For presenters and for the doctor's per-tenant view: a booking whose key
     * version has been retired is not "wrong", it is UNVERIFIABLE, and a
     * manager needs to be able to see that difference before a customer is
     * standing at the counter (§6).
     */
    public function isVerifiable(Appointment $appointment): bool
    {
        return $appointment->verification_code_digest !== null
            && $this->keyring->knows(self::KEY, $appointment->verification_code_key_version);
    }
}
