<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Security\Exceptions\MissingKeyVersion;
use Illuminate\Contracts\Config\Repository as Config;
use SensitiveParameter;

/**
 * Named, VERSIONED HMAC peppers.
 *
 * The problem it exists for: Meta Style stores digests of values that cannot be
 * re-derived. A booking verification code is ten characters a person types, and
 * the raw code is never written anywhere — so "rotate the secret and rehash"
 * is not available, because there is nothing to rehash from.
 *
 * The answer is to record WHICH KEY wrote each digest, next to the digest:
 *
 *     verification_code_digest        HMAC-SHA256(normalised code, pepper[v1])
 *     verification_code_key_version   "v1"
 *
 * Verification then uses the version ON THE ROW and no other. It never tries
 * every configured key in turn — that would quietly turn a retired key into a
 * live one, and would make a rotation unobservable. A row whose version is not
 * configured FAILS CLOSED: {@see MissingKeyVersion} rather than a silent false,
 * so a deployment missing a key is a loud fault instead of "nobody's code works
 * any more and nothing said why" (docs/24-BOOKING-VERIFICATION.md §§5–6).
 *
 * ## Not the application key
 *
 * `APP_KEY` encrypts RECOVERABLE data and is rotated by re-encrypting that
 * data. These peppers protect values that can never be recovered. Coupling the
 * two would mean an `APP_KEY` rotation silently invalidated every outstanding
 * booking code — a failure whose cause nobody would find (ADR-069).
 *
 * ## What never happens here
 *
 * Key material is never returned, logged, audited, serialised or put in an
 * exception message. {@see describe()} exists so the production doctor can
 * report on configuration without any caller ever holding a key.
 */
final class Keyring
{
    public function __construct(private readonly Config $config) {}

    /**
     * The version new digests must be written with.
     *
     * @throws MissingKeyVersion when no active version is configured, or when
     *                           the active version has no key material — both
     *                           are deployments that would write digests
     *                           nothing could verify
     */
    public function activeVersion(string $name): string
    {
        $active = $this->config->get("security.keys.{$name}.active");

        if (! is_string($active) || $active === '') {
            throw MissingKeyVersion::noActiveVersion($name);
        }

        // Asserted, not just read: an active version naming a key that is not
        // configured is the one case that would corrupt data rather than refuse
        // it — every digest written would carry a version nothing can resolve.
        $this->material($name, $active);

        return $active;
    }

    /**
     * The digest for a value, under one key version.
     *
     * `$version` null means the active one. Writers pass null; verifiers pass
     * the version stored beside the digest they are checking.
     *
     * @throws MissingKeyVersion
     */
    public function hmac(string $name, #[SensitiveParameter] string $value, ?string $version = null): string
    {
        $version ??= $this->activeVersion($name);

        return hash_hmac('sha256', $value, $this->material($name, $version));
    }

    /**
     * Whether a version can be resolved at all — for callers that need to
     * decide rather than to fail, such as a presenter reporting that a booking's
     * code can no longer be checked.
     */
    public function knows(string $name, ?string $version): bool
    {
        if ($version === null || $version === '') {
            return false;
        }

        try {
            $this->material($name, $version);

            return true;
        } catch (MissingKeyVersion) {
            return false;
        }
    }

    /**
     * What is configured, WITHOUT any key material.
     *
     * For `metastyle:doctor`. Byte lengths are reported because "set" is not
     * the property that matters — a four-character placeholder is set.
     *
     * @return array{active: string|null, versions: array<string, int>}
     */
    public function describe(string $name): array
    {
        $active = $this->config->get("security.keys.{$name}.active");

        $lengths = [];

        foreach ($this->versions($name) as $version => $secret) {
            $lengths[$version] = mb_strlen($this->decode($secret), '8bit');
        }

        return [
            'active' => is_string($active) && $active !== '' ? $active : null,
            'versions' => $lengths,
        ];
    }

    /**
     * @throws MissingKeyVersion
     */
    private function material(string $name, string $version): string
    {
        $secret = $this->versions($name)[$version] ?? null;

        if ($secret === null) {
            // The name and the version only. Never a hint about what IS
            // configured — an exception message is the least controlled string
            // in the system.
            throw MissingKeyVersion::forVersion($name, $version);
        }

        return $this->decode($secret);
    }

    /**
     * @return array<string, string>
     */
    private function versions(string $name): array
    {
        $versions = $this->config->get("security.keys.{$name}.versions", []);

        if (! is_array($versions)) {
            return [];
        }

        $resolved = [];

        foreach ($versions as $version => $secret) {
            if (is_string($version) && $version !== '' && is_string($secret) && $secret !== '') {
                $resolved[$version] = $secret;
            }
        }

        return $resolved;
    }

    /**
     * Raw bytes from a configured value.
     *
     * `base64:` is understood so a key can be generated the same way `APP_KEY`
     * is, and so 32 random BYTES can be written into an environment file
     * safely. Anything else is taken literally — a passphrase is a bad key, but
     * it is not this class's job to reject one at runtime and lock a center out
     * of its own bookings. The doctor is where that is refused, before traffic.
     */
    private function decode(#[SensitiveParameter] string $secret): string
    {
        if (! str_starts_with($secret, 'base64:')) {
            return $secret;
        }

        $decoded = base64_decode(mb_substr($secret, 7), true);

        return $decoded === false ? $secret : $decoded;
    }
}
