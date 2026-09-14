<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies staff credentials inside an already-initialised tenant context.
 *
 * The tenant must be resolved BEFORE this runs — by host for the web UI, or
 * from the center public key on the API login endpoint. This action never
 * chooses a tenant; it authenticates against whichever one is bound, which is
 * what keeps "which center" out of the credential check.
 *
 * ## Rate limited HERE, not on the route
 *
 * `throttle:login` sits on `GET /login`, which renders the form. The credential
 * check itself arrives at `/livewire/update`, because a Livewire action posts
 * to Livewire's own endpoint rather than to the route that rendered the
 * component — so that route limit never saw a single staff sign-in attempt. The
 * API path was IP-limited; the web path was not limited at all.
 *
 * Limiting inside the action closes that, and makes the two channels identical
 * rather than merely similar: `TokenController` and the Livewire `Login`
 * component both call this, so both get the same buckets, the same thresholds
 * and the same 429. It is the same {@see LoginThrottle} the customer sign-in
 * uses — one implementation, not a staff-only copy of it.
 *
 * Both callers run this inside `TenantContext::run()`, so every bucket is
 * scoped to the center being signed into: one center's failed logins can
 * neither exhaust nor be observed through another center's allowance.
 */
final class AuthenticateStaff
{
    /**
     * A real bcrypt hash that matches nothing, generated once per process.
     *
     * Verified against when the identifier is unknown, so an unknown account
     * costs the same time as a wrong password and the endpoint cannot be used
     * to discover who works at a center.
     *
     * Generated rather than hardcoded because Laravel's hasher rejects a
     * hand-written string outright — and memoised rather than regenerated,
     * because hashing on every miss would cost TWO bcrypt rounds against the
     * real path's one, reintroducing the timing signal from the other side.
     */
    private static ?string $decoyHash = null;

    public function __construct(
        private readonly Audit $audit,
        private readonly LoginThrottle $throttle,
    ) {}

    /**
     * @throws AuthenticationFailed
     * @throws TooManyLoginAttempts
     */
    public function __invoke(string $identifier, string $password, AuditSource $source = AuditSource::Web): User
    {
        // Keyed on the canonical spelling, so `Owner@Alpha.test` and
        // `owner@alpha.test` count against one bucket — keying on the raw input
        // would hand an attacker a fresh allowance per capitalisation, the same
        // hole the customer login closed by normalising the number first.
        //
        // Only the identifier is ever handed to the throttle, and the throttle
        // fingerprints it before it becomes a key. The password is not a
        // parameter of any of this.
        $canonical = $this->canonical($identifier);

        $this->throttle->assertAllowed($canonical);

        $user = $this->findByIdentifier($identifier);

        // Hash::check runs even when no user was found, against a dummy hash,
        // so the response time does not reveal whether the identifier exists.
        $hash = $user instanceof User && $user->password !== null
            ? $user->password
            : self::decoyHash();

        $passwordMatches = Hash::check($password, $hash);

        if ($user === null || ! $user->canAuthenticate() || ! $passwordMatches) {
            // Counted against what the CALLER typed, before anything is known
            // about whether it matched. An identifier nobody has trips the
            // limit at exactly the same attempt as a real one, so the 429
            // carries no information the attacker did not already supply.
            $this->throttle->recordFailure($canonical);

            $this->auditFailure($identifier, $source, $user);

            throw AuthenticationFailed::invalidCredentials();
        }

        // The identifier's buckets only. The address bucket survives a success,
        // because a carrier NAT or a shared office puts many people behind one
        // address and one correct guess must not hand an attacker a free reset.
        // Same policy as the customer sign-in.
        $this->throttle->clear($canonical);

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(new AuditEvent(
            action: 'identity.login.succeeded',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Staff, $source, (string) $user->getKey(), $user->name),
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
        ));

        return $user;
    }

    private static function decoyHash(): string
    {
        return self::$decoyHash ??= Hash::make('meta-style/no-such-account');
    }

    /**
     * One spelling of what was typed, for the limiter and the audit trail.
     *
     * Lower-cased rather than parsed. The lookup matches `email` or `phone`
     * exactly, so a canonical form the lookup would not itself accept would
     * bucket two genuinely different logins together.
     */
    private function canonical(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    private function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where(fn ($query) => $query->where('email', $identifier)->orWhere('phone', $identifier))
            ->first();

        return $user;
    }

    /**
     * Records a failed attempt without storing the identifier.
     *
     * A truncated hash is enough to correlate repeated attempts against the
     * same account — which is what a security investigation actually needs —
     * without writing an email address or phone number into a durable log
     * (docs/08-AUDIT-SECURITY.md §17).
     */
    private function auditFailure(string $identifier, AuditSource $source, ?User $user): void
    {
        $this->audit->record(new AuditEvent(
            action: 'identity.login.failed',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Guest, $source, null, 'unauthenticated'),
            severity: AuditSeverity::Warning,
            targetType: $user !== null ? User::class : null,
            targetId: $user?->uuid,
            meta: [
                'identifier_fingerprint' => substr(hash('sha256', $this->canonical($identifier)), 0, 16),
                'reason' => match (true) {
                    $user === null => 'unknown_identifier',
                    ! $user->is_active => 'inactive_account',
                    $user->password === null => 'not_activated',
                    default => 'wrong_password',
                },
            ],
        ));
    }
}
