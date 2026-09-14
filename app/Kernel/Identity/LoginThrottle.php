<?php

declare(strict_types=1);

namespace App\Kernel\Identity;

use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * Brute-force limiting for credential checks, keyed by WHO is being attacked as
 * well as by who is attacking.
 *
 * ## Why the route limiter was not enough
 *
 * Phase 5 limited customer login by IP on the route. Two holes:
 *
 * 1. **Distributed attempts.** A thousand addresses trying one phone number
 *    each get five attempts apiece against that account and never trip an
 *    IP bucket. Rate limiting the attacker does nothing for the victim.
 * 2. **Livewire does not pass through it.** A Livewire action posts to
 *    `/livewire/update`, not to the route that rendered the component, so
 *    `throttle:login` on `/customer/sign-in` never runs for the actual sign-in.
 *
 * So the limit lives in the ACTION, where every channel reaches it — the API,
 * the web form, and the WhatsApp bot when it arrives — and it counts attempts
 * against the identifier as well as against the address
 * (docs/13-ROADMAP.md Phase 6 §1).
 *
 * ## It does not reveal whether an account exists
 *
 * The identifier bucket is keyed on what the CALLER typed, and it is
 * incremented on every failure regardless of whether anything matched. Ten
 * attempts against a number nobody has trips it exactly as fast as ten against
 * a real customer, so the 429 carries no information the attacker did not
 * already supply.
 *
 * ## The identifier is fingerprinted, not stored
 *
 * A cache key is not a secure store: it lands in Redis, in `KEYS *` output, in
 * a slow-log, in whatever a support engineer runs during an incident. Keying on
 * `Fingerprint::of()` keeps the buckets exact and keeps phone numbers out of
 * infrastructure that was never designed to hold PII (ADR-042).
 *
 * ## Tenant-scoped
 *
 * Every key is prefixed with the tenant id, so one center's traffic cannot
 * exhaust another's allowance and a center's failed logins are not visible in
 * another's bucket (docs/08-AUDIT-SECURITY.md §13).
 */
final class LoginThrottle
{
    /** Per identifier, per minute. Generous for a typo, hostile to a script. */
    private const IDENTIFIER_PER_MINUTE = 5;

    /**
     * Per identifier, per hour.
     *
     * The one that actually bounds a distributed attack: spreading attempts
     * across a thousand addresses does not raise this ceiling, because it is
     * keyed on the account being attacked rather than on who is attacking it.
     */
    private const IDENTIFIER_PER_HOUR = 20;

    /** Per address, per minute — across every identifier it tries. */
    private const ADDRESS_PER_MINUTE = 10;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @throws TooManyLoginAttempts
     */
    public function assertAllowed(string $identifier): void
    {
        foreach ($this->buckets($identifier) as [$key, $max, $seconds]) {
            unset($seconds);

            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw new TooManyLoginAttempts($this->limiter->availableIn($key));
            }
        }
    }

    /**
     * Counts one failed attempt against every bucket.
     */
    public function recordFailure(string $identifier): void
    {
        foreach ($this->buckets($identifier) as [$key, $max, $seconds]) {
            unset($max);

            $this->limiter->hit($key, $seconds);
        }
    }

    /**
     * Clears the IDENTIFIER's buckets after a successful sign-in.
     *
     * The address bucket is deliberately left alone. A shared office or a
     * mobile carrier NAT means many people behind one address, and letting one
     * success reset it would hand an attacker a free reset every time they
     * guessed any account correctly.
     */
    public function clear(string $identifier): void
    {
        $fingerprint = $this->fingerprint($identifier);

        $this->limiter->clear($this->key('id:min', $fingerprint));
        $this->limiter->clear($this->key('id:hour', $fingerprint));
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function buckets(string $identifier): array
    {
        $fingerprint = $this->fingerprint($identifier);

        return [
            [$this->key('id:min', $fingerprint), self::IDENTIFIER_PER_MINUTE, 60],
            [$this->key('id:hour', $fingerprint), self::IDENTIFIER_PER_HOUR, 3600],
            [$this->key('ip:min', $this->address()), self::ADDRESS_PER_MINUTE, 60],
        ];
    }

    private function key(string $scope, string $value): string
    {
        return 'login:'.($this->tenants->id() ?? 'none').':'.$scope.':'.$value;
    }

    /**
     * A stable, non-reversible stand-in for what was typed.
     *
     * Falls back to a constant when the value is unusable, so an empty
     * identifier still counts against a bucket rather than escaping limiting
     * entirely.
     */
    private function fingerprint(string $identifier): string
    {
        return Fingerprint::of($identifier) ?? 'blank';
    }

    private function address(): string
    {
        if (! app()->bound('request')) {
            // Console and queue callers have no address. They still get the
            // identifier buckets; there is simply nothing to key an address one
            // on, and inventing 'cli' would put every job in one bucket.
            return 'none';
        }

        /** @var Request $request */
        $request = app('request');

        return (string) $request->ip();
    }
}
