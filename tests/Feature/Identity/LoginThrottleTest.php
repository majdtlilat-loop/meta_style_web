<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Http\MiddlewareOrderViolation;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use App\Livewire\Customer\SignIn;
use App\Modules\Customers\Application\Actions\AuthenticateCustomer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Phase 5 security follow-ups
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §1.
|
| Two concerns carried over from Phase 5, addressed while touching the
| authentication and customer flows rather than in a separate hardening phase.
|
*/

function grantAccountsForThrottleTests(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'customer_accounts'],
        ['mode' => 'grant', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('bounds attempts against ONE account, not just one address', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($customer);

        $authenticate = app(AuthenticateCustomer::class);

        // Five wrong passwords are refused as credentials…
        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect(fn (): CustomerAccount => $authenticate('0750 123 4567', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }

        // …and the sixth is refused as a rate limit, distinctly. A caller must
        // be told to stop and wait rather than left assuming their password is
        // wrong and trying harder.
        expect(fn (): CustomerAccount => $authenticate('0750 123 4567', 'wrong-password'))
            ->toThrow(TooManyLoginAttempts::class);

        // Even the CORRECT password now waits. That is the point: the limit is
        // on the account being attacked.
        expect(fn (): CustomerAccount => $authenticate('0750 123 4567', 'correct-horse-battery-staple'))
            ->toThrow(TooManyLoginAttempts::class);
    });
});

it('counts every spelling of one number against the same bucket', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($customer);

        $authenticate = app(AuthenticateCustomer::class);

        // The same account, typed six different ways. Keying on the raw input
        // would hand an attacker a fresh allowance per format.
        $spellings = [
            '0750 123 4567',
            '+9647501234567',
            '964-750-123-4567',
            '00964 750 123 4567',
            '0750-123-4567',
            '(0750) 1234567',
        ];

        foreach ($spellings as $index => $spelling) {
            $thrown = null;

            try {
                $authenticate($spelling, 'wrong-password');
            } catch (Throwable $e) {
                $thrown = $e;
            }

            expect($thrown)->toBeInstanceOf(
                $index < 5 ? AuthenticationFailed::class : TooManyLoginAttempts::class
            );
        }
    });
});

it('reveals nothing about whether an account exists', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $authenticate = app(AuthenticateCustomer::class);

        // A number nobody has. The bucket counts what the CALLER typed, so it
        // trips at exactly the same point as a real account would — the 429
        // carries no information the attacker did not already supply.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect(fn (): CustomerAccount => $authenticate('0770 999 8888', 'anything'))
                ->toThrow(AuthenticationFailed::class);
        }

        expect(fn (): CustomerAccount => $authenticate('0770 999 8888', 'anything'))
            ->toThrow(TooManyLoginAttempts::class);
    });
});

it('clears the identifier buckets on a successful sign-in', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($customer);

        $authenticate = app(AuthenticateCustomer::class);

        // Four fumbles, then the right password.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            expect(fn (): CustomerAccount => $authenticate('0750 123 4567', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }

        expect($authenticate('0750 123 4567', 'correct-horse-battery-staple'))
            ->toBeInstanceOf(CustomerAccount::class);

        // A person who mistyped four times and then got in is not mid-attack.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            expect(fn (): CustomerAccount => $authenticate('0750 123 4567', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }
    });
});

it('keeps one center\'s limiter buckets out of another\'s', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    grantAccountsForThrottleTests($alpha['tenant']->id);
    grantAccountsForThrottleTests($beta['tenant']->id);

    // The same person, a customer of both centers, with the same number.
    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
            $this->seedCustomerAccount($customer);
        });
    }

    $this->asCenter($alpha['tenant'], function (): void {
        $authenticate = app(AuthenticateCustomer::class);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            try {
                $authenticate('0750 123 4567', 'wrong-password');
            } catch (Throwable) {
                // Exhausting Alpha's bucket is the point.
            }
        }
    });

    // Beta is untouched. One center's traffic must not exhaust another's
    // allowance (docs/08-AUDIT-SECURITY.md §13).
    $this->asCenter($beta['tenant'], function (): void {
        expect(app(AuthenticateCustomer::class)('0750 123 4567', 'correct-horse-battery-staple'))
            ->toBeInstanceOf(CustomerAccount::class);
    });
});

it('keeps the raw phone number out of the rate-limiter keyspace', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($customer);

        try {
            app(AuthenticateCustomer::class)('0750 123 4567', 'wrong-password');
        } catch (Throwable) {
            // Expected.
        }

        // A cache key is not a secure store: it lands in Redis, in `KEYS *`
        // output, in a slow log, in whatever a support engineer runs during an
        // incident. The bucket is keyed on a fingerprint (ADR-042).
        $fingerprint = Fingerprint::of('+9647501234567');

        $limiter = app(RateLimiter::class);
        $tenantId = app(TenantContext::class)->id();

        expect($limiter->attempts("login:{$tenantId}:id:min:{$fingerprint}"))->toBe(1)
            ->and($limiter->attempts("login:{$tenantId}:id:min:+9647501234567"))->toBe(0);
    });
});

it('limits the Livewire sign-in, which the route throttle never sees', function (): void {
    $center = $this->registerCenter();

    grantAccountsForThrottleTests($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomerAccount($customer);
    });

    $key = $this->publicKeyOf($center['tenant']);

    /*
     * THE HOLE PHASE 5 LEFT. A Livewire action posts to `/livewire/update`, not
     * to the route that rendered the component, so `throttle:login` on
     * `/customer/sign-in` never runs for the actual sign-in. Putting the limit
     * in the Action is what closes it — and gives the WhatsApp bot the same
     * protection for free when it arrives.
     */
    $component = Livewire::test(SignIn::class)
        ->set('centerKey', $key)
        ->set('phone', '0750 123 4567');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'wrong-password')->call('login')
            ->assertSet('error', 'Those details do not match our records.');
    }

    $component->set('password', 'wrong-password')->call('login');

    expect($component->get('error'))->toContain('Too many attempts');
});

/*
|--------------------------------------------------------------------------
| The Livewire tenant-binding bug this phase found
|--------------------------------------------------------------------------
*/

it('carries tenant resolution onto Livewire component updates', function (): void {
    /*
     * A LATENT BUG FROM PHASE 3, found while wiring the calendar.
     *
     * Livewire component actions POST to `/livewire/update`, which carries only
     * the `web` middleware group. Livewire re-runs a filtered subset of the
     * original route's middleware — the PERSISTENT ones — and that list
     * includes `Authenticate` by default but did not include `ResolveTenant`.
     *
     * So every Livewire action in the center area authenticated a staff user
     * whose model lives in the tenant database, with no tenant bound. It failed
     * closed on `TenantConnectionGuard`, which is the right failure and the
     * wrong outcome: the admin area's interactivity did not work in a browser.
     *
     * `Livewire::test()` bypasses that endpoint entirely and binds the tenant
     * itself, which is why five phases of tests never saw it.
     */
    $persistent = Livewire::getPersistentMiddleware();

    expect($persistent)->toContain(ResolveTenant::class)
        ->toContain(SetLocale::class)
        // Registered too, so a Livewire page on the public menu keeps its
        // center. Only the middleware actually on the original route is ever
        // gathered, so this cannot leak a path-resolved tenant onto an
        // authenticated route (ADR-036).
        ->toContain(ResolvePublicTenant::class);

    // And the guard that makes it fatal rather than a comment.
    expect(fn () => app(MiddlewareOrderGuard::class)->assertLivewirePersists([]))
        ->toThrow(MiddlewareOrderViolation::class, 'persistent middleware');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
