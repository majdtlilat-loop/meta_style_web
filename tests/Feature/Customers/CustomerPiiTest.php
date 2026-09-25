<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Privacy\ContactMasker;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Center\Customers as CustomersPage;
use App\Modules\Customers\Application\Actions\ManageCustomerNotes;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| PII: field-level masking and note visibility
|--------------------------------------------------------------------------
|
| docs/06-AUTH-ROLES-PERMISSIONS.md §6 · docs/13-ROADMAP.md Phase 5 §§11, 15.
|
| Meta Style's first field-level control. `customer.view` finds a customer;
| `customer.contact.view` is what shows how to reach them. Most staff need the
| first and not the second, and the difference has to hold on every surface —
| hiding a value in a template still sends it to the browser.
|
*/

it('masks a phone and an email without revealing them', function (): void {
    $masker = new ContactMasker;

    expect($masker->phone('+9647501234567', full: true))->toBe('+9647501234567')
        // Country code kept (knowing a customer is local is not sensitive and
        // helps staff read a list); enough of the tail to recognise a record
        // you already know, not enough to learn one you do not.
        ->and($masker->phone('+9647501234567', full: false))->toBe('+964 ••••••••67')
        ->and($masker->phone('+9647501234567', full: false))->not->toContain('7501234')
        ->and($masker->email('sara.ahmed@example.com', full: true))->toBe('sara.ahmed@example.com')
        ->and($masker->email('sara.ahmed@example.com', full: false))->toBe('s•••@•••.com')
        ->and($masker->phone(null, full: false))->toBeNull()
        ->and($masker->email(null, full: false))->toBeNull();
});

it('gives full contact details to staff who hold the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');

        $viewer = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerContactView],
            'privileged@alpha.test',
        );

        $shape = app(CustomerPresenter::class)->summary($customer, $viewer);

        expect($shape['phone'])->toBe('+9647501234567')
            ->and($shape['email'])->toBe('sara@example.com')
            ->and($shape['contact_masked'])->toBeFalse();
    });
});

it('masks contact details for staff who hold only customer.view', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');

        $viewer = $this->staffWith([Permission::CustomerView], 'limited@alpha.test');

        $shape = app(CustomerPresenter::class)->summary($customer, $viewer);

        expect($shape['phone'])->not->toBe('+9647501234567')
            ->and($shape['phone'])->toContain('••')
            ->and($shape['email'])->not->toBe('sara@example.com')
            // Honest about the fact something is hidden. A masked value that
            // looked real would not be.
            ->and($shape['contact_masked'])->toBeTrue()
            // The name is not masked — it is how staff identify a record.
            ->and($shape['name'])->toBe('Sara Ahmed');
    });
});

it('applies the same rule on the API and the web, from one implementation', function (): void {
    $center = $this->registerCenter();

    $limited = $this->asCenter($center['tenant'], function () {
        $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');

        return $this->staffWith([Permission::CustomerView], 'apiweb@alpha.test');
    });

    // --- API -------------------------------------------------------------
    $token = $this->apiTokenFor($center['tenant'], $limited);

    $body = (string) $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/customers')
        ->assertOk()->getContent();

    expect($body)->not->toContain('+9647501234567')
        ->not->toContain('0750 123 4567')
        ->not->toContain('sara@example.com')
        ->toContain('Sara Ahmed');

    // --- Web -------------------------------------------------------------
    $html = $this->asCenter($center['tenant'], function () use ($limited): string {
        return (string) Livewire::actingAs($limited)->test(CustomersPage::class)->html();
    });

    // The same values, absent for the same reason, because both surfaces call
    // the same presenter (Phase 5 §15).
    expect($html)->not->toContain('+9647501234567')
        ->not->toContain('0750 123 4567')
        ->not->toContain('sara@example.com')
        ->toContain('Sara Ahmed');
});

it('refuses to search by phone for someone who may not see phones', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $this->seedCustomer('Ali Hassan', '0770 987 6543');

        $limited = $this->staffWith([Permission::CustomerView], 'nosearch@alpha.test');
        $full = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerContactView],
            'cansearch@alpha.test',
        );

        $query = app(CustomerQuery::class);

        // Searching a full number while only seeing a masked one would be an
        // oracle: type a number, learn whether that person is a customer here
        // (docs/06 §6, rule 4).
        expect($query->paginate(['search' => '0750 123 4567'], $limited)->total())->toBe(0)
            ->and($query->paginate(['search' => '0750 123 4567'], $full)->total())->toBe(1)
            // Name search still works for both — it is not a masked field.
            ->and($query->paginate(['search' => 'Sara'], $limited)->total())->toBe(1);
    });
});

it('does not hand back a masked value in the edit form', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');

        $limited = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerUpdate],
            'editor@alpha.test',
        );

        // Opening the editor must not become the way round the masking.
        $component = Livewire::actingAs($limited)
            ->test(CustomersPage::class)
            ->call('edit', $customer->uuid);

        $component->assertSet('phone', '')->assertSet('email', '');
    });
});

it('does not erase a contact detail the editor was never shown', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');

        $limited = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerUpdate],
            'editor2@alpha.test',
        );

        Livewire::actingAs($limited)
            ->test(CustomersPage::class)
            ->call('edit', $customer->uuid)
            ->set('name', 'Sara A. Ahmed')
            ->call('save')
            ->assertHasNoErrors();

        $customer->refresh();

        // Saving a form whose phone field was blank because of masking must not
        // wipe the number.
        expect($customer->name)->toBe('Sara A. Ahmed')
            ->and($customer->phone)->toBe('+9647501234567')
            ->and($customer->email)->toBe('sara@example.com');
    });
});

/*
|--------------------------------------------------------------------------
| Notes
|--------------------------------------------------------------------------
*/

it('shows internal notes only to staff who may read them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();

        app(ManageCustomerNotes::class)->add($customer, 'Prefers a quiet appointment.', $owner);

        $reader = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerNoteView],
            'reader@alpha.test',
        );

        $nobody = $this->staffWith([Permission::CustomerView], 'nonotes@alpha.test');

        $presenter = app(CustomerPresenter::class);

        expect($presenter->detail($customer, $reader)['notes'])->toHaveCount(1)
            // Omitted entirely for someone without the permission — an empty
            // list would at least confirm there are none.
            ->and($presenter->detail($customer, $nobody)['notes'])->toBe([]);
    });
});

it('keeps a manager-only note away from ordinary staff', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();
        $notes = app(ManageCustomerNotes::class);

        $notes->add($customer, 'Prefers Sara.', $owner, NoteVisibility::Internal);
        $notes->add($customer, 'Disputed a charge; handle carefully.', $owner, NoteVisibility::ManagerOnly);

        $ordinary = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerNoteView],
            'ordinary@alpha.test',
        );

        $manager = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerNoteView, Permission::CustomerNoteManage],
            'manager@alpha.test',
        );

        $presenter = app(CustomerPresenter::class);

        $ordinaryNotes = $presenter->detail($customer, $ordinary)['notes'];
        $managerNotes = $presenter->detail($customer, $manager)['notes'];

        // The second note is exactly the kind that must not be on the screen
        // reception turns toward the customer.
        expect($ordinaryNotes)->toHaveCount(1)
            ->and($ordinaryNotes[0]['body'])->toBe('Prefers Sara.')
            ->and($managerNotes)->toHaveCount(2);

        $encoded = json_encode($ordinaryNotes, JSON_THROW_ON_ERROR);

        expect($encoded)->not->toContain('Disputed a charge');
    });
});

it('requires the manage permission to write a note', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $customer = $this->seedCustomer();

        $reader = $this->staffWith(
            [Permission::CustomerView, Permission::CustomerNoteView],
            'readonly@alpha.test',
        );

        expect(fn () => app(ManageCustomerNotes::class)->add($customer, 'Sneaky.', $reader))
            ->toThrow(AuthorizationException::class);
    });
});

it('never records the body of a note in the audit trail', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();

        app(ManageCustomerNotes::class)->add(
            $customer,
            'Allergic to a particular product — do not use it.',
            $owner,
        );

        $entry = TenantAuditLog::query()
            ->where('action', 'crm.customer_note.created')->firstOrFail();

        $raw = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);

        // Copying a note into the audit trail would duplicate whatever
        // sensitive thing it says into a second table with different readers
        // and a different retention rule (Phase 5 §22).
        expect($raw)->not->toContain('Allergic')
            ->not->toContain('do not use it')
            ->and($entry->meta['visibility'])->toBe('internal')
            ->and($entry->meta['length'])->toBeInt();
    });
});

it('refuses to delete a note through the wrong customer', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $sara = $this->seedCustomer('Sara', '0750 111 1111');
        $ali = $this->seedCustomer('Ali', '0770 222 2222');

        $note = app(ManageCustomerNotes::class)->add($sara, 'Sara note.', $owner);

        // Scoped to the customer, so a note uuid from another record cannot be
        // deleted through this customer's endpoint.
        expect(fn () => app(ManageCustomerNotes::class)->delete($ali, $note, $owner))
            ->toThrow(AuthorizationException::class);
    });
});

it('never leaks a customer note to the public menu', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->publishMenu();
        $this->seedCatalog();

        $customer = $this->seedCustomer();

        app(ManageCustomerNotes::class)->add($customer, 'Never quote below 15,000.', $owner);
    });

    // Since Phase 15 the public menu answers on the center's own host, with its slug in the path.
    $slug = $center['registration']->requested_slug;
    $body = (string) $this->getJson(app(PlatformHosts::class)->centerUrl($slug, '/api/v1/menu/'.$slug))
        ->assertOk()->getContent();

    // The public surface builds from an allow-list, so a customer note has no
    // route onto it at all.
    expect($body)->not->toContain('Never quote below')
        ->not->toContain('Sara Ahmed')
        ->not->toContain('+9647501234567');
});

it('refuses customer endpoints entirely without customer.view', function (): void {
    $center = $this->registerCenter();

    $nobody = $this->asCenter(
        $center['tenant'],
        fn () => $this->staffWith([Permission::ServiceView], 'noaccess@alpha.test'),
    );

    $token = $this->apiTokenFor($center['tenant'], $nobody);

    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/customers')
        ->assertForbidden();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
