<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Customer CRM
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 5 §§2, 3, 10, 19, 20, 22.
|
| The record every later module hangs off. Two properties matter most: one
| person is one customer however they spell their number, and a customer belongs
| to the CENTER rather than to one of its branches.
|
*/

it('creates a customer with a normalised phone identity', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    // Asserted INSIDE the tenant closure: `isRegistered()` queries the account
    // relation, and reading it after tenancy has ended fails closed — which is
    // the guard working, not a bug.
    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = app(SaveCustomer::class)(
            CustomerInput::fromArray([
                'name' => 'Sara Ahmed',
                'phone' => '0750 123 4567',
                'email' => 'Sara@Example.COM',
            ]),
            $owner,
        );

        expect($customer->name)->toBe('Sara Ahmed')
            // Canonical for matching, as typed for showing back.
            ->and($customer->phone)->toBe('+9647501234567')
            ->and($customer->phone_display)->toBe('0750 123 4567')
            // Lower-cased, so two spellings of one address are one customer.
            ->and($customer->email)->toBe('sara@example.com')
            ->and($customer->source)->toBe(CustomerSource::Staff)
            ->and($customer->isRegistered())->toBeFalse()
            ->and($customer->isArchived())->toBeFalse();
    });
});

it('finds one customer whatever spelling of the number is used', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCustomer('Sara', '+964 750 123 4567');

        foreach (['0750 123 4567', '07501234567', '964-750-123-4567', '+9647501234567'] as $spelling) {
            expect(Customer::findByPhone($spelling)?->name)->toBe('Sara');
        }
    });
});

it('refuses a second customer on a number that already belongs to someone', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        // Explicit, never silent. Auto-merging would be wrong the one time a
        // family shares a number; a duplicate would be wrong every other time
        // (Phase 5 §19).
        expect(fn () => app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Someone Else', 'phone' => '+964 750 123 4567']),
            $owner,
        ))->toThrow(ValidationException::class, 'already belongs to Sara Ahmed');

        expect(Customer::query()->count())->toBe(1);
    });
});

it('lets a customer keep their own number when edited', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer('Sara', '0750 123 4567');

        $updated = app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Sara Ahmed', 'phone' => '0750 123 4567']),
            $owner,
            $customer,
        );

        expect($updated->name)->toBe('Sara Ahmed')
            ->and($updated->phone)->toBe('+9647501234567');
    });
});

it('allows a customer with no phone at all', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // A walk-in placeholder is a real operational case: reception starts a
        // record before asking for a number (Phase 5 §3).
        $a = app(SaveCustomer::class)(CustomerInput::fromArray(['name' => 'Walk-in 1']), $owner);
        $b = app(SaveCustomer::class)(CustomerInput::fromArray(['name' => 'Walk-in 2']), $owner);

        expect($a->phone)->toBeNull()
            ->and($b->phone)->toBeNull()
            // Two null phones do not collide: MySQL's unique index ignores null.
            ->and(Customer::query()->count())->toBe(2);
    });
});

it('rejects a phone that cannot be made canonical', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // Storing it would create exactly the duplicate the design prevents.
        expect(fn () => app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Nope', 'phone' => 'not a phone']),
            $owner,
        ))->toThrow(ValidationException::class);
    });
});

it('belongs to the center, not to a branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('customers');

        // A locked decision: the same person books at two branches of one
        // center and keeps one profile (Phase 5 §20).
        expect($columns)->not->toContain('branch_id');
    });
});

it('holds no credential column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('customers');

        // CRM data and authentication are separate tables, so merging them
        // would take a migration rather than a moment's inattention.
        expect($columns)->not->toContain('password')
            ->not->toContain('remember_token')
            // And none of the later modules' numbers, which nobody would keep
            // correct (Phase 5 §2).
            ->not->toContain('loyalty_points')
            ->not->toContain('wallet_balance')
            ->not->toContain('total_spent');
    });
});

it('archives instead of deleting, and deactivates the login with it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();

        $account = $customer->account()->create(['password' => 'secret-password', 'is_active' => true]);

        app(ArchiveCustomer::class)($customer, $owner);

        $customer->refresh();

        // The row survives: bookings and invoices will reference it.
        expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue()
            ->and($customer->archived_at)->not->toBeNull()
            // An archived customer who can still sign in is a person invisible
            // to staff and visible to themselves.
            ->and($account->refresh()->is_active)->toBeFalse()
            ->and(Customer::query()->active()->whereKey($customer->id)->exists())->toBeFalse();
    });
});

it('restores a customer without restoring their ability to sign in', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();
        $account = $customer->account()->create(['password' => 'secret-password', 'is_active' => true]);

        app(ArchiveCustomer::class)($customer, $owner);
        app(ArchiveCustomer::class)->restore($customer, $owner);

        // Restoring a CRM record is clerical; restoring someone's login is a
        // different decision needing customer.account.manage.
        expect($customer->refresh()->archived_at)->toBeNull()
            ->and($account->refresh()->is_active)->toBeFalse();
    });
});

it('records marketing consent with the date it was given', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Sara', 'marketing_opt_in' => true]),
            $owner,
        );

        // Consent without a timestamp is not evidence of consent.
        expect($customer->marketing_opt_in)->toBeTrue()
            ->and($customer->marketing_opt_in_at)->not->toBeNull();

        $optedInAt = $customer->marketing_opt_in_at;

        app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Sara', 'marketing_opt_in' => false]),
            $owner,
            $customer,
        );

        // Withdrawal does not erase the record that it was once given —
        // "opted in on this date, out later" is what a regulator asks for.
        expect($customer->refresh()->marketing_opt_in)->toBeFalse()
            ->and($customer->marketing_opt_in_at?->toIso8601String())->toBe($optedInAt?->toIso8601String());
    });
});

it('requires the right permission for each operation', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer();

        // Can look, cannot touch.
        $viewer = $this->staffWith([Permission::CustomerView], 'viewer@alpha.test');

        expect(fn () => app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'New']),
            $viewer,
        ))->toThrow(AuthorizationException::class, 'may not add customers');

        expect(fn () => app(SaveCustomer::class)(
            CustomerInput::fromArray(['name' => 'Renamed']),
            $viewer,
            $customer,
        ))->toThrow(AuthorizationException::class, 'may not change customers');

        expect(fn () => app(ArchiveCustomer::class)($customer, $viewer))
            ->toThrow(AuthorizationException::class, 'may not archive customers');
    });
});

/*
|--------------------------------------------------------------------------
| Audit privacy
|--------------------------------------------------------------------------
*/

it('audits a customer change without recording their phone or email', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = app(SaveCustomer::class)(
            CustomerInput::fromArray([
                'name' => 'Sara Ahmed',
                'phone' => '0750 123 4567',
                'email' => 'sara@example.com',
            ]),
            $owner,
        );

        app(SaveCustomer::class)(
            CustomerInput::fromArray([
                'name' => 'Sara Ahmed',
                'phone' => '0770 987 6543',
                'email' => 'sara.new@example.com',
            ]),
            $owner,
            $customer,
        );

        $entries = TenantAuditLog::query()->whereIn('action', [
            'crm.customer.created', 'crm.customer.updated',
        ])->get();

        expect($entries)->toHaveCount(2);

        // The values never appear, in any column, in any form.
        foreach ($entries as $entry) {
            $raw = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);

            expect($raw)->not->toContain('+9647501234567')
                ->not->toContain('0750 123 4567')
                ->not->toContain('07501234567')
                ->not->toContain('sara@example.com')
                ->not->toContain('sara.new@example.com');
        }

        $updated = $entries->firstWhere('action', 'crm.customer.updated');

        // What IS recorded: that a phone exists, and a fingerprint that
        // correlates without revealing (Phase 5 §22).
        expect($updated->before['has_phone'])->toBeTrue()
            ->and($updated->before['phone_fingerprint'])->toBe(Fingerprint::of('+9647501234567'))
            ->and($updated->after['phone_fingerprint'])->toBe(Fingerprint::of('+9647709876543'))
            ->and($updated->before['phone_fingerprint'])->not->toBe($updated->after['phone_fingerprint']);
    });
});

it('produces a fingerprint that is keyed, not a bare digest', function (): void {
    $phone = '+9647501234567';

    // A plain sha256 of a phone number is not private: the Iraqi mobile space
    // is about 10^9, which a laptop enumerates in seconds. An audit log full of
    // bare digests would be an audit log full of phone numbers.
    expect(Fingerprint::of($phone))->not->toBe(substr(hash('sha256', $phone), 0, 16))
        ->and(Fingerprint::of($phone))->toBe(Fingerprint::of($phone))
        ->and(Fingerprint::of($phone))->not->toBe(Fingerprint::of('+9647709876543'))
        ->and(Fingerprint::of(null))->toBeNull();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
