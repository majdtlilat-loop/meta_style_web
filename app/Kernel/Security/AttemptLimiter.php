<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Named, tenant-scoped abuse limits, with their numbers in `config/limits.php`.
 *
 * The generalisation of {@see LoginThrottle}, which stays
 * separate because credential checking has rules of its own — clearing the
 * identifier bucket on success, never clearing the address one — that do not
 * generalise. What DOES generalise is everything around them: tenant prefixes,
 * fingerprinted keys, several windows per bucket, and refusing before doing the
 * work rather than after (docs/13-ROADMAP.md Phase 13 §§39, 73).
 *
 * ## Generic on purpose
 *
 * This knows nothing about WhatsApp, RAYAN or bookings. A caller names a bucket
 * and supplies a key; the shape of the limit comes from configuration. That is
 * what lets it live in the Kernel while the modules that use it sit far above —
 * the Kernel must never learn when a conversation should hand off or when a
 * message should send.
 *
 * ## Keys are fingerprinted
 *
 * The values keyed on here are phone numbers, booking references and provider
 * account identifiers. A cache key is not a secure store — it reaches Redis, a
 * `KEYS *` during an incident, a slow log — so what goes in is
 * {@see Fingerprint::of()} rather than the value (ADR-042).
 *
 * ## Tenant-scoped
 *
 * Every key carries the tenant id, so one center's flood cannot exhaust
 * another's allowance and one center's traffic is not observable in another's
 * buckets (docs/08-AUDIT-SECURITY.md §13).
 *
 * ## An unconfigured bucket does not limit, and says so
 *
 * A missing bucket allows everything. That is the deliberate choice for a
 * limiter — failing OPEN here means abuse gets through, failing CLOSED means a
 * typo in a config key silently takes a feature offline for every center. The
 * protection against the typo is {@see isConfigured()}, checked by the
 * production doctor, not a runtime refusal nobody would be able to diagnose.
 */
final class AttemptLimiter
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Refuses if any window for this bucket is already exhausted.
     *
     * Checked BEFORE the work, and {@see record()} is called after a failure —
     * or, for buckets that limit volume rather than guessing, immediately. The
     * two are separate calls because "was this allowed" and "this happened" are
     * different questions, and a limiter that counted on the way in could never
     * express "only failures count".
     *
     * @throws TooManyAttempts
     */
    public function assertAllowed(string $bucket, string $value): void
    {
        foreach ($this->windows($bucket) as [$max, $seconds]) {
            $key = $this->key($bucket, $value, $seconds);

            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw new TooManyAttempts($bucket, $this->limiter->availableIn($key));
            }
        }
    }

    /**
     * Counts one occurrence against every window of the bucket.
     */
    public function record(string $bucket, string $value): void
    {
        foreach ($this->windows($bucket) as [$max, $seconds]) {
            unset($max);

            $this->limiter->hit($this->key($bucket, $value, $seconds), $seconds);
        }
    }

    /**
     * Whether the bucket would allow one more, without throwing.
     *
     * For callers that must not raise — a webhook handler deciding to drop a
     * flood quietly rather than answer an error to a provider that would then
     * retry it (docs/25-WHATSAPP.md §9).
     */
    public function allows(string $bucket, string $value): bool
    {
        foreach ($this->windows($bucket) as [$max, $seconds]) {
            if ($this->limiter->tooManyAttempts($this->key($bucket, $value, $seconds), $max)) {
                return false;
            }
        }

        return true;
    }

    public function clear(string $bucket, string $value): void
    {
        foreach ($this->windows($bucket) as [$max, $seconds]) {
            unset($max);

            $this->limiter->clear($this->key($bucket, $value, $seconds));
        }
    }

    /**
     * Does this bucket have at least one usable window configured?
     *
     * For the production doctor. A bucket that resolves to nothing limits
     * nothing, and the whole failure mode of a rate limit is that it looks
     * identical whether it is working or not.
     */
    public function isConfigured(string $bucket): bool
    {
        return $this->windows($bucket) !== [];
    }

    /**
     * @return list<array{0: int, 1: int}> [max, seconds]
     */
    private function windows(string $bucket): array
    {
        $configured = $this->config->get('limits.'.$bucket);

        if (! is_array($configured)) {
            return [];
        }

        $windows = [];

        foreach ($configured as $window) {
            if (! is_array($window)) {
                continue;
            }

            $max = $window['max'] ?? null;
            $seconds = $window['seconds'] ?? null;

            if (is_numeric($max) && is_numeric($seconds) && (int) $max > 0 && (int) $seconds > 0) {
                $windows[] = [(int) $max, (int) $seconds];
            }
        }

        return $windows;
    }

    /**
     * One key PER WINDOW.
     *
     * The window's length is part of the key, and it has to be: a bucket with a
     * per-minute and a per-hour window shares nothing between them. With a
     * single key, `record()` would increment the same counter once per window —
     * so a five-a-minute limit would trip after three attempts, and the hourly
     * window would be meaningless. A test guessing at a booking code found this
     * exactly (docs/13-ROADMAP.md Phase 13 §39).
     */
    private function key(string $bucket, string $value, int $seconds): string
    {
        return 'limit:'.($this->tenants->id() ?? 'none').':'.$bucket.':'.$seconds.':'.(Fingerprint::of($value) ?? 'blank');
    }
}
