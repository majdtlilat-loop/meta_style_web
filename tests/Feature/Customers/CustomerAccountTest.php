<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\AuthenticateCustomer;
use App\Modules\Customers\Application\Actions\RegisterCustomerAccount;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Customer accounts and the guest → registered upgrade
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 5 §§4, 5, 6, 7, 9.
|
| The upgrade is the part that has to be right. Everything later — bookings,
| sales, reviews, loyalty, packages — hangs off the customer id, so a duplicate
| created at signup splits a person's history permanently and nobody notices
| until they ask why their visits are missing.
|
*/

/**
 * Turns `customer_accounts` off for the center under test.
 *
 * A tenant-scoped REVOKE override, not a deletion of the plan's grant. Plans
 * live in the control database and are shared by every test in the run, so
 * deleting a row from one leaks into the next test that expects the trial plan
 * intact — which is exactly what it did before this was corrected.
 */
function disableCustomerAccounts(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'customer_accounts'],
        ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

/** Turns it on, whatever the plan says. */
function enableCustomerAccounts(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'customer_accounts'],
        [
            'mode' => 'grant',
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('lets a customer exist with no account at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer();

        // Guest is the ABSENCE of an account row, not a flag and not a second
        // table that could fall out of step (Phase 5 §4).
        expect($customer->isRegistered())->toBeFalse()
            ->and($customer->account()->exists())->toBeFalse()
            ->and(CustomerAccount::query()->count())->toBe(0);
    });
});

it('links a signup to the customer who already exists — never a second record', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // Reception created them weeks ago, as a walk-in.
        $existing = app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Sara Ahmed', 'phone' => '0750 123 4567']),
            $owner,
        );

        $originalId = $existing->id;

        // They sign up later, typing their number a different way.
        $result = app(RegisterCustomerAccount::class)('+964 750 123 4567', 'a-strong-password', 'Sara');

        expect($result['linked_existing'])->toBeTrue()
            // THE assertion of Phase 5: same customer, same id, one history.
            ->and($result['customer']->id)->toBe($originalId)
            ->and(Customer::query()->count())->toBe(1)
            ->and(CustomerAccount::query()->count())->toBe(1)
            ->and($result['customer']->fresh()->isRegistered())->toBeTrue();
    });
});

it('does not overwrite CRM data a member of staff curated', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(SaveCustomer::class)(
            CustomerInput::fromArray([
                'name' => 'Sara Ahmed',
                'phone' => '0750 123 4567',
                'preferred_locale' => 'ar',
            ]),
            $owner,
        );

        // A different spelling of the name at signup must not rewrite the
        // record, and a different locale must not either (Phase 5 §7).
        $result = app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'sara', 'en');

        expect($result['customer']->name)->toBe('Sara Ahmed')
            ->and($result['customer']->preferred_locale)->toBe('ar');
    });
});

it('fills a genuinely empty field rather than leaving it blank', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCustomer('Sara', '0750 123 4567');

        $result = app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara', 'ckb');

        // Conservative means "fill gaps", not "change nothing ever".
        expect($result['customer']->preferred_locale)->toBe('ckb');
    });
});

it('creates exactly one customer when nobody exists yet', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $result = app(RegisterCustomerAccount::class)('0770 987 6543', 'a-strong-password', 'Ali');

        expect($result['linked_existing'])->toBeFalse()
            ->and(Customer::query()->count())->toBe(1)
            ->and($result['customer']->name)->toBe('Ali')
            ->and($result['customer']->source)->toBe(CustomerSource::SelfRegistration)
            ->and($result['customer']->phone)->toBe('+9647709876543');
    });
});

it('refuses a second account for a customer who already has one', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        expect(fn () => app(RegisterCustomerAccount::class)('0750 123 4567', 'another-password', 'Sara'))
            ->toThrow(ValidationException::class, 'already exists');

        // The one-to-one is enforced by a unique index, not just by this check.
        expect(CustomerAccount::query()->count())->toBe(1);
    });
});

it('refuses to resurrect an archived customer through signup', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer('Sara', '0750 123 4567');

        app(ArchiveCustomer::class)($customer, $owner);

        // Letting them self-resurrect would silently undo a staff decision.
        expect(fn () => app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara'))
            ->toThrow(ValidationException::class);
    });
});

/*
|--------------------------------------------------------------------------
| The entitlement
|--------------------------------------------------------------------------
*/

it('blocks self-registration when the center does not offer accounts', function (): void {
    $center = $this->registerCenter();

    disableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        expect(fn () => app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara'))
            ->toThrow(EntitlementRequired::class);

        expect(fn () => app(AuthenticateCustomer::class)('0750 123 4567', 'a-strong-password'))
            ->toThrow(EntitlementRequired::class);
    });
});

it('still lets staff keep customer records when accounts are off', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    disableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // `customer_accounts` controls the customer's LOGIN surface, not
        // whether the center may run a CRM (Phase 5 §26).
        $customer = app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Sara', 'phone' => '0750 123 4567']),
            $owner,
        );

        expect($customer->exists)->toBeTrue()
            ->and(Customer::query()->count())->toBe(1);
    });

    // And the staff API is unaffected too.
    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->postJson('/api/v1/tenant/customers', ['name' => 'Ali', 'phone' => '0770 111 2222'])
        ->assertStatus(201);
});

it('refuses customer registration over the API when accounts are off', function (): void {
    $center = $this->registerCenter();

    disableCustomerAccounts($center['tenant']->id);

    $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
    ])->assertForbidden()->assertJsonPath('error.code', 'ENTITLEMENT.NOT_AVAILABLE');
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('signs a customer in with their phone in any spelling', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        $account = app(AuthenticateCustomer::class)('+964 750 123 4567', 'a-strong-password');

        expect($account->canAuthenticate())->toBeTrue()
            ->and($account->last_login_at)->not->toBeNull()
            // No verification provider exists, so this is false and honestly so.
            ->and($account->hasVerifiedPhone())->toBeFalse();
    });
});

it('gives one answer to every kind of failed sign-in', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        $messages = [];

        // Unknown number, no account, wrong password, deactivated account.
        foreach ([
            ['0790 000 0000', 'a-strong-password'],
            ['0750 123 4567', 'the-wrong-password'],
        ] as [$phone, $password]) {
            try {
                app(AuthenticateCustomer::class)($phone, $password);
            } catch (AuthenticationFailed $e) {
                $messages[] = $e->getMessage();
            }
        }

        $guest = $this->seedCustomer('Guest', '0780 000 0000');

        try {
            app(AuthenticateCustomer::class)('0780 000 0000', 'a-strong-password');
        } catch (AuthenticationFailed $e) {
            $messages[] = $e->getMessage();
        }

        unset($guest, $owner);

        // Distinguishing them would turn this into a way to discover who is a
        // customer of this center.
        expect($messages)->toHaveCount(3)
            ->and(array_unique($messages))->toHaveCount(1);
    });
});

it('refuses a deactivated account and an archived customer', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $result = app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        $result['account']->forceFill(['is_active' => false])->save();

        expect(fn () => app(AuthenticateCustomer::class)('0750 123 4567', 'a-strong-password'))
            ->toThrow(AuthenticationFailed::class);

        $result['account']->forceFill(['is_active' => true])->save();

        app(ArchiveCustomer::class)($result['customer'], $owner);

        // Archiving deactivates the login, so this is refused twice over.
        expect(fn () => app(AuthenticateCustomer::class)('0750 123 4567', 'a-strong-password'))
            ->toThrow(AuthenticationFailed::class);
    });
});

it('never records a password or a phone number in the audit trail', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        app(RegisterCustomerAccount::class)('0750 123 4567', 'a-very-secret-password', 'Sara');

        try {
            app(AuthenticateCustomer::class)('0750 123 4567', 'the-wrong-password');
        } catch (AuthenticationFailed) {
            // Expected.
        }

        app(AuthenticateCustomer::class)('0750 123 4567', 'a-very-secret-password');

        $entries = TenantAuditLog::query()->where('action', 'like', 'crm.customer_account.%')->get();

        expect($entries->count())->toBeGreaterThanOrEqual(3);

        foreach ($entries as $entry) {
            $raw = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);

            expect($raw)->not->toContain('a-very-secret-password')
                ->not->toContain('the-wrong-password')
                ->not->toContain('+9647501234567')
                ->not->toContain('0750 123 4567');
        }

        // A failure still records enough to correlate repeated attempts.
        $failure = $entries->firstWhere('action', 'crm.customer_account.login_failed');

        expect($failure->meta['phone_fingerprint'])->toBeString()
            ->and($failure->meta['reason'])->toBe('wrong_password')
            ->and($failure->severity)->toBe('warning');
    });
});

it('never marks a phone verified, because nothing can verify one yet', function (): void {
    $center = $this->registerCenter();

    enableCustomerAccounts($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $result = app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        // Claiming verification without a provider would be a lie the rest of
        // the system then trusts (ADR-040).
        expect($result['account']->phone_verified_at)->toBeNull()
            ->and($result['account']->hasVerifiedPhone())->toBeFalse()
            ->and(CustomerAccount::query()->whereNotNull('phone_verified_at')->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
