<?php

declare(strict_types=1);

use App\Kernel\Identity\Actions\AuthenticateStaff;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Identity\Models\User;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Livewire\Auth\Login;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Staff credential throttling
|--------------------------------------------------------------------------
|
| The hole the customer sign-in closed in Phase 6 §1, left open on the staff
| side: `throttle:login` sits on `GET /login`, which renders the form, while
| the credential check arrives at `/livewire/update`. The route limit never saw
| a single staff attempt.
|
| The fix is the SAME `LoginThrottle`, called from `AuthenticateStaff`, so the
| web form and the API token endpoint share one set of buckets rather than two
| implementations that drift.
|
*/

/** The password `registerCenter()` gives every owner it creates. */
const STAFF_PASSWORD = 'correct-horse-battery-staple';

/**
 * Every key currently in the rate limiter's store.
 *
 * Reads the array store directly rather than probing keys one at a time: the
 * question "is the password anywhere in this keyspace" cannot be answered by
 * guessing which key it might be in.
 *
 * @return list<string>
 */
function limiterKeys(): array
{
    $repository = Cache::store();

    expect($repository)->toBeInstanceOf(Repository::class);

    $store = $repository->getStore();

    expect($store)->toBeInstanceOf(ArrayStore::class);

    $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);

    return array_map(strval(...), array_keys(is_array($storage) ? $storage : []));
}

it('throttles the Livewire staff sign-in, which the route limit never sees', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $component = Livewire::test(Login::class)
        ->set('centerKey', $key)
        ->set('identifier', 'owner@alpha.test');

    // Five wrong passwords are refused as credentials…
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'wrong-password')
            ->call('submit')
            ->assertHasErrors('identifier')
            ->assertSee('do not match our records');
    }

    // …and the sixth is refused as a rate limit, distinctly. Somebody being
    // told to wait needs to know that, or they keep trying and keep extending
    // the block.
    $component->set('password', 'wrong-password')
        ->call('submit')
        ->assertHasErrors('identifier')
        ->assertSee('Too many attempts');
});

it('makes even the correct password wait while the bucket is hot', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $authenticate = app(AuthenticateStaff::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect(fn (): User => $authenticate('owner@alpha.test', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }

        // THE POINT OF THE IDENTIFIER BUCKET. The limit is on the account being
        // attacked, so it cannot be walked past by finally guessing right.
        expect(fn (): User => $authenticate('owner@alpha.test', STAFF_PASSWORD))
            ->toThrow(TooManyLoginAttempts::class);
    });
});

it('clears the identifier buckets on a successful sign-in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $authenticate = app(AuthenticateStaff::class);

        // Four fumbles, then the right password.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            expect(fn (): User => $authenticate('owner@alpha.test', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }

        expect($authenticate('owner@alpha.test', STAFF_PASSWORD))->toBeInstanceOf(User::class);

        // Somebody who mistyped four times and then got in is not mid-attack.
        // Same policy as the customer sign-in: the identifier's buckets clear,
        // the address bucket does not.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            expect(fn (): User => $authenticate('owner@alpha.test', 'wrong-password'))
                ->toThrow(AuthenticationFailed::class);
        }
    });
});

it('counts one spelling of an identifier, not one per capitalisation', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $authenticate = app(AuthenticateStaff::class);

        // The lookup is case-insensitive, so these are all the same account.
        // Keying on the raw input would hand an attacker a fresh allowance per
        // spelling of it.
        $spellings = [
            'owner@alpha.test',
            'Owner@Alpha.test',
            'OWNER@ALPHA.TEST',
            '  owner@alpha.test  ',
            'oWnEr@aLpHa.TeSt',
            'Owner@ALPHA.test',
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

it('keeps one center\'s staff buckets out of another\'s', function (): void {
    // The same person, working at two centers, with the same work email.
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@shared.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@shared.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $authenticate = app(AuthenticateStaff::class);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            try {
                $authenticate('owner@shared.test', 'wrong-password');
            } catch (Throwable) {
                // Exhausting Alpha's bucket is the point.
            }
        }

        expect(fn (): User => $authenticate('owner@shared.test', STAFF_PASSWORD))
            ->toThrow(TooManyLoginAttempts::class);
    });

    // Beta is untouched. One center's traffic must not exhaust another's
    // allowance, and a center must not be able to see another's failed logins
    // by watching its own (docs/08-AUDIT-SECURITY.md §13).
    $this->asCenter($beta['tenant'], function (): void {
        expect(app(AuthenticateStaff::class)('owner@shared.test', STAFF_PASSWORD))
            ->toBeInstanceOf(User::class);
    });
});

it('trips at the same attempt whether or not the account exists', function (): void {
    // Two centers, because the address bucket is per center too — running both
    // halves in one would let the ADDRESS limit end the second sequence early
    // and the test would prove nothing about the identifier bucket.
    $real = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $nobody = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $failures = function (string $identifier): array {
        $authenticate = app(AuthenticateStaff::class);
        $seen = [];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            try {
                $authenticate($identifier, 'wrong-password');
                $seen[] = 'authenticated';
            } catch (Throwable $e) {
                $seen[] = $e::class.': '.$e->getMessage();
            }
        }

        return $seen;
    };

    $existing = $this->asCenter($real['tenant'], fn (): array => $failures('owner@alpha.test'));
    $unknown = $this->asCenter($nobody['tenant'], fn (): array => $failures('nobody@nowhere.test'));

    // Identical, message for message and exception for exception. The bucket
    // counts what the CALLER typed, so an identifier nobody has trips it at
    // exactly the same attempt as a real one and the 429 carries no
    // information the attacker did not already supply.
    expect($unknown)->toBe($existing)
        ->and($existing[4])->toContain(AuthenticationFailed::class)
        ->and($existing[5])->toContain(TooManyLoginAttempts::class);
});

it('shares one throttle between the Livewire form and the API token endpoint', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $component = Livewire::test(Login::class)
        ->set('centerKey', $key)
        ->set('identifier', 'owner@alpha.test');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'wrong-password')->call('submit')->assertHasErrors('identifier');
    }

    // ONE request, so the route's own `throttle:login` (5/min per address) has
    // not been reached — a 429 here can only have come from the central
    // throttle the Livewire attempts filled. Two channels, one set of buckets.
    $response = $this->postJson('/api/v1/public/auth/token', [
        'center_key' => $key,
        'identifier' => 'owner@alpha.test',
        // The CORRECT password, to be sure this is the limiter answering and
        // not the credentials.
        'password' => STAFF_PASSWORD,
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMIT.EXCEEDED')
        ->assertHeader('Retry-After');

    expect($response->json('error.details.retry_after'))->toBeInt();
});

it('never lets a password reach a limiter key, a log line or an audit row', function (): void {
    $center = $this->registerCenter();
    $secret = 'hunter2-the-password-under-test';

    $this->asCenter($center['tenant'], function () use ($secret): void {
        try {
            app(AuthenticateStaff::class)('owner@alpha.test', $secret);
        } catch (Throwable) {
            // Expected. The attempt is what puts rows and keys in place.
        }

        // Nowhere in the limiter keyspace…
        foreach (limiterKeys() as $limiterKey) {
            expect($limiterKey)->not->toContain($secret);
        }

        // …and nowhere in the audit trail, which records that an attempt
        // happened and against which account, never what was tried.
        $rows = DB::connection('tenant')->table('audit_logs')
            ->where('action', 'identity.login.failed')
            ->get();

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect(json_encode($row))->not->toContain($secret);
        }
    });

    // The application log too — a throttle that wrote what it was counting
    // would move the secret from one store into another.
    $log = storage_path('logs/laravel.log');

    if (is_file($log)) {
        expect((string) file_get_contents($log))->not->toContain($secret);
    }
});

it('keeps the raw identifier out of the rate-limiter keyspace', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        try {
            app(AuthenticateStaff::class)('owner@alpha.test', 'wrong-password');
        } catch (Throwable) {
            // Expected.
        }

        // A cache key is not a secure store: it lands in Redis, in `KEYS *`
        // output, in a slow log, in whatever a support engineer runs during an
        // incident. The bucket is keyed on a fingerprint (ADR-042).
        $limiter = app(RateLimiter::class);
        $tenantId = app(TenantContext::class)->id();
        $fingerprint = Fingerprint::of('owner@alpha.test');

        expect($limiter->attempts("login:{$tenantId}:id:min:{$fingerprint}"))->toBe(1)
            ->and($limiter->attempts("login:{$tenantId}:id:min:owner@alpha.test"))->toBe(0);

        foreach (limiterKeys() as $limiterKey) {
            expect($limiterKey)->not->toContain('owner@alpha.test');
        }
    });
});

it('counts an unknown center key too, so the limit does not announce a real one', function (): void {
    $center = $this->registerCenter();

    // Rate limiting the credential check made "too many attempts" the sixth
    // answer for a REAL center. Without counting these, a sixth "credentials do
    // not match" would confirm the key names no center — an oracle this form
    // did not have before the throttle was added.
    $component = Livewire::test(Login::class)
        ->set('centerKey', 'not-a-real-center-key')
        ->set('identifier', 'owner@alpha.test');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'wrong-password')
            ->call('submit')
            ->assertSee('do not match our records');
    }

    $component->set('password', 'wrong-password')
        ->call('submit')
        ->assertSee('Too many attempts');

    // And it consumed nobody's allowance: the tenant-less scope is shared by no
    // center, so a real one still has its full five.
    $this->asCenter($center['tenant'], function (): void {
        expect(app(AuthenticateStaff::class)('owner@alpha.test', STAFF_PASSWORD))
            ->toBeInstanceOf(User::class);
    });
});

it('carries the unknown-center count onto the API token endpoint', function (): void {
    $this->registerCenter();

    // Five through the form, against a center key that names nothing.
    $component = Livewire::test(Login::class)
        ->set('centerKey', 'not-a-real-center-key')
        ->set('identifier', 'owner@alpha.test');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->set('password', 'wrong-password')->call('submit')->assertHasErrors('identifier');
    }

    // ONE request here, so the endpoint's own `throttle:login` (5/min per
    // address) has not been reached. Switching channels must not buy a fresh
    // allowance — the same bucket has to answer.
    $this->postJson('/api/v1/public/auth/token', [
        'center_key' => 'not-a-real-center-key',
        'identifier' => 'owner@alpha.test',
        'password' => 'wrong-password',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMIT.EXCEEDED');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
