<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Signs a customer in, by phone and password.
 *
 * ONE ANSWER FOR EVERY FAILURE. Unknown number, no account, wrong password,
 * deactivated account and archived customer all produce the same refusal. Any
 * distinction turns this into a way to discover who is a customer of this
 * center — which, for a beauty clinic or a laser center, is information people
 * have a real interest in keeping private.
 *
 * The timing is levelled too: a miss still performs one hash comparison against
 * a decoy, so "no such customer" and "wrong password" take the same time. The
 * same approach the staff login uses.
 *
 * RATE LIMITED HERE, NOT ON THE ROUTE. Phase 5 limited by IP on the route,
 * which left two holes: a distributed attack gets a fresh allowance per address
 * and never bounds the account being attacked, and a Livewire sign-in posts to
 * `/livewire/update` so the route's throttle never runs at all. Limiting inside
 * the Action closes both and gives the WhatsApp bot the same protection for
 * free when it arrives (docs/13-ROADMAP.md Phase 6 §1, {@see LoginThrottle}).
 */
final class AuthenticateCustomer
{
    private static ?string $decoyHash = null;

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
        private readonly LoginThrottle $throttle,
    ) {}

    /**
     * @throws AuthenticationFailed
     * @throws TooManyLoginAttempts
     */
    public function __invoke(
        string $phone,
        string $password,
        AuditSource $source = AuditSource::Web,
    ): CustomerAccount {
        $this->entitlements->ensure('customer_accounts');

        // Keyed on the CANONICAL number, so `0750…` and `+964750…` count
        // against one bucket — otherwise ten spellings would be ten allowances
        // against the same account.
        $identifier = $this->canonical($phone);

        $this->throttle->assertAllowed($identifier);

        $parsed = PhoneNumber::parse($phone);

        $customer = $parsed === null
            ? null
            : Customer::query()->with('account')->where('phone', $parsed->e164)->first();

        $account = $customer?->account;

        if (! $this->passwordMatches($account, $password)) {
            $this->throttle->recordFailure($identifier);
            $this->recordFailure($phone, $customer, $account, $source);

            throw new AuthenticationFailed('Those details do not match our records.');
        }

        // Re-checked after the password, not before: refusing early would make
        // "deactivated" measurably faster than "wrong password". A non-null
        // account always has a customer — it is a `hasOne` from that side.
        if ($account === null || ! $account->canAuthenticate() || $customer->isArchived()) {
            $this->throttle->recordFailure($identifier);
            $this->recordFailure($phone, $customer, $account, $source);

            throw new AuthenticationFailed('Those details do not match our records.');
        }

        // The identifier's buckets only. The address bucket survives a success,
        // because many people share one address behind a carrier NAT and one
        // correct guess must not hand an attacker a free reset.
        $this->throttle->clear($identifier);

        $account->forceFill(['last_login_at' => Carbon::now()])->save();

        $this->audit->record(new AuditEvent(
            action: 'crm.customer_account.login',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Customer, $source, $account->uuid, $customer->name),
            targetType: Customer::class,
            targetId: $customer->uuid,
            targetLabel: $customer->name,
        ));

        return $account;
    }

    /**
     * Constant-ish time. A miss still costs one bcrypt comparison, so the
     * absence of an account is not measurable from outside.
     */
    private function passwordMatches(?CustomerAccount $account, string $password): bool
    {
        $hash = $account?->password;

        if ($hash === null) {
            Hash::check($password, self::decoy());

            return false;
        }

        return Hash::check($password, $hash);
    }

    /**
     * A real bcrypt hash, generated once per process.
     *
     * Memoised deliberately: a miss must cost exactly one hash comparison, the
     * same as a hit. Hand-writing a hash string instead is rejected by the
     * hasher as not being a Bcrypt hash.
     */
    private static function decoy(): string
    {
        return self::$decoyHash ??= Hash::make('decoy-'.bin2hex(random_bytes(8)));
    }

    /**
     * The normalised number if it parses, otherwise what was typed — so two
     * attempts at the same unparseable input still correlate.
     */
    private function canonical(string $phone): string
    {
        $parsed = PhoneNumber::parse($phone);

        return $parsed === null ? $phone : $parsed->e164;
    }

    private function recordFailure(
        string $phone,
        ?Customer $customer,
        ?CustomerAccount $account,
        AuditSource $source,
    ): void {
        $this->audit->record(new AuditEvent(
            action: 'crm.customer_account.login_failed',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Guest, $source, null, 'unauthenticated'),
            severity: AuditSeverity::Warning,
            targetType: $customer !== null ? Customer::class : null,
            targetId: $customer?->uuid,
            meta: [
                // The number itself is never recorded. A fingerprint still
                // correlates repeated attempts against one account, which is
                // what an investigation needs (docs/08-AUDIT-SECURITY.md §17).
                'phone_fingerprint' => Fingerprint::of($this->canonical($phone)),
                'reason' => match (true) {
                    $customer === null => 'unknown_customer',
                    $account === null => 'no_account',
                    ! $account->is_active => 'inactive_account',
                    $account->password === null => 'not_activated',
                    $customer->isArchived() => 'archived_customer',
                    default => 'wrong_password',
                },
            ],
        ));
    }
}
