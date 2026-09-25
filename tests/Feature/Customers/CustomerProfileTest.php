<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Notes\NoteVisibility;
use App\Livewire\Center\Customers as CustomersPage;
use App\Livewire\Center\Customers\NotesPanel;
use App\Livewire\Center\Customers\Profile;
use App\Livewire\Center\Customers\TagsDrawer;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\ManageCustomerNotes;
use App\Modules\Customers\Application\Actions\ManageCustomerTag;
use App\Modules\Customers\Application\Actions\SetCustomerAccountStatus;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager CRM: list, form, customer page, login switch, notes
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 5 §§3, 11, 15, 16 · ADR-041 · docs/08 (no PII in a
| URL). The list, the shared phone picker, the page with its permission-gated
| tabs, the login switch a restored customer needs, and notes with authors.
|
*/

it('never binds the customer search to the URL, while the filters are', function (): void {
    $attributes = static fn (string $property): array => array_map(
        static fn (ReflectionAttribute $a): string => $a->getName(),
        (new ReflectionProperty(CustomersPage::class, $property))->getAttributes(),
    );

    // A search can be a phone number or an email; a query string ends up in
    // history, logs and shared links.
    expect(in_array(Url::class, $attributes('search'), true))->toBeFalse()
        ->and(in_array(Url::class, $attributes('archived'), true))->toBeTrue()
        ->and(in_array(Url::class, $attributes('visited'), true))->toBeTrue();
});

it('stores a number typed for another country through the shared phone picker', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->assertSet('phoneCountry', 'IQ')
            ->set('name', 'Deniz Kaya')
            ->set('phoneCountry', 'TR')
            ->set('phone', '532 123 4567')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        // A phone is optional: a customer can be added with a name alone.
        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->set('name', 'No Phone')
            ->call('save')
            ->assertHasNoErrors();

        expect(Customer::query()->where('name', 'Deniz Kaya')->value('phone'))->toBe('+905321234567')
            ->and(Customer::query()->where('name', 'No Phone')->value('phone'))->toBeNull();

        // A number that does not fit the chosen country is refused in the form.
        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->set('name', 'Too Short')
            ->set('phone', '750 12')
            ->call('save')
            ->assertHasErrors('phone');

        expect(Customer::query()->count())->toBe(2);
    });
});

it('offers the existing customer instead of creating a second one on the same number', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->set('name', 'Somebody Else')
            ->set('phone', '750 123 4567')
            ->call('save')
            ->assertHasErrors('phone')
            ->assertSet('duplicate', ['uuid' => $sara->uuid, 'name' => 'Sara Ahmed'])
            ->assertSee(route('center.customers.show', ['uuid' => $sara->uuid]), false);

        expect(Customer::query()->count())->toBe(1);
    });
});

it('prefills the phone picker from the stored identity for staff who may see it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer('Sara Ahmed', '+90 532 123 4567', 'sara@example.com');

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('edit', $customer->uuid)
            ->assertSet('phoneCountry', 'TR')
            ->assertSet('phone', '5321234567')
            ->assertSet('email', 'sara@example.com')
            ->set('name', 'Sara A. Ahmed')
            ->call('save')
            ->assertHasNoErrors();

        // Unchanged identity, new name — and no second record.
        expect($customer->refresh()->phone)->toBe('+905321234567')
            ->and($customer->name)->toBe('Sara A. Ahmed')
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('finds a customer by any spelling or part of their number, only for contact viewers', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.com');
        $this->seedCustomer('Ali Hassan', '0770 987 6543');

        $full = $this->staffWith([Permission::CustomerView, Permission::CustomerContactView], 'full@alpha.test');
        $limited = $this->staffWith([Permission::CustomerView], 'limited@alpha.test');
        $query = app(CustomerQuery::class);

        expect($query->paginate(['search' => '+964 750 123 4567'], $full)->total())->toBe(1)
            ->and($query->paginate(['search' => '0750 123'], $full)->total())->toBe(1)
            ->and($query->paginate(['search' => 'SARA@EXAMPLE'], $full)->total())->toBe(1)
            ->and($query->paginate(['search' => '0750 123'], $limited)->total())->toBe(0)
            ->and($query->paginate(['search' => 'sara@example'], $limited)->total())->toBe(0);
    });
});

it('renders the customer page in every interface language with no raw keys', function (string $locale, string $direction): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $direction): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        app(ManageCustomerNotes::class)->add($customer, 'Prefers mornings.', $owner);

        $this->actingAs($owner);

        foreach (['overview', 'notes', 'bookings', 'visits', 'purchases', 'loyalty', 'plans', 'reviews'] as $tab) {
            $html = $this->get("http://{$slug}.localhost:8000/manager/customers/{$customer->uuid}?tab={$tab}&locale={$locale}")
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
                ->assertSee('Sara Ahmed')
                ->getContent();

            expect(preg_match('/\bmanager_(customers|benefits)\.[a-z_]+\.[a-z_]+/', strip_tags((string) $html)))->toBe(0);
        }

        $this->get("http://{$slug}.localhost:8000/manager/customers?locale={$locale}")
            ->assertOk()
            ->assertSee('Sara Ahmed');
    });
})->with([
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
    'Kurdish Sorani' => ['ckb', 'rtl'],
]);

it('answers 404 for an unknown customer and 403 without customer.view', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $customer = $this->seedCustomer();
        $nobody = $this->staffWith([Permission::ServiceView], 'noaccess@alpha.test');

        $this->actingAs($owner);
        $this->get("http://{$slug}.localhost:8000/manager/customers/00000000-0000-4000-8000-000000000000")->assertNotFound();

        $this->actingAs($nobody);
        $this->get("http://{$slug}.localhost:8000/manager/customers/{$customer->uuid}")->assertForbidden();
        $this->get("http://{$slug}.localhost:8000/manager/customers")->assertForbidden();
    });
});

it('shows each tab only to the staff whose permission it needs', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();
        $reception = $this->staffWith([Permission::CustomerView, Permission::CustomerNoteView], 'reception@alpha.test');

        $limited = Livewire::actingAs($reception)->test(Profile::class, ['uuid' => $customer->uuid]);
        $all = Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $customer->uuid]);

        expect($limited->viewData('tabs'))->toBe(['overview', 'notes'])
            ->and($all->viewData('tabs'))->toBe(Profile::TABS);

        // Asking for a tab the viewer may not open lands on the overview.
        Livewire::actingAs($reception)
            ->test(Profile::class, ['uuid' => $customer->uuid])
            ->call('showTab', 'purchases')
            ->assertSet('tab', 'overview');
    });
});

it('switches a login off and on, and keeps it off through an archive and restore', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();
        $customer->account()->create(['password' => 'a-strong-password', 'is_active' => true]);

        Livewire::actingAs($owner)
            ->test(Profile::class, ['uuid' => $customer->uuid])
            ->call('setLogin', false)
            ->assertSet('noticeTone', 'success');

        expect(CustomerAccount::query()->sole()->is_active)->toBeFalse();

        Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $customer->uuid])->call('setLogin', true);
        expect(CustomerAccount::query()->sole()->is_active)->toBeTrue();

        // Archiving turns the login off; restoring the record does NOT turn it
        // back on — that is this separate, audited decision.
        Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $customer->uuid])->call('archive');
        expect(CustomerAccount::query()->sole()->is_active)->toBeFalse();

        // An archived customer's login cannot be switched on.
        Livewire::actingAs($owner)
            ->test(Profile::class, ['uuid' => $customer->uuid])
            ->call('setLogin', true)
            ->assertSet('noticeTone', 'danger');
        expect(CustomerAccount::query()->sole()->is_active)->toBeFalse();

        Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $customer->uuid])->call('restore');
        expect($customer->refresh()->archived_at)->toBeNull()
            ->and(CustomerAccount::query()->sole()->is_active)->toBeFalse();

        Livewire::actingAs($owner)->test(Profile::class, ['uuid' => $customer->uuid])->call('setLogin', true);
        expect(CustomerAccount::query()->sole()->is_active)->toBeTrue();

        $actions = TenantAuditLog::query()->where('action', 'like', 'crm.customer_account.%')->orderBy('id')->pluck('action')->all();
        $raw = json_encode(TenantAuditLog::query()->where('action', 'like', 'crm.customer_account.%')->get()->map->getAttributes(), JSON_THROW_ON_ERROR);

        expect($actions)->toBe([
            'crm.customer_account.deactivated',
            'crm.customer_account.activated',
            'crm.customer_account.activated',
        ])->and(str_contains($raw, '+9647501234567'))->toBeFalse();
    });
});

it('refuses the login switch without customer.account.manage, and for a guest', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();
        $customer->account()->create(['password' => 'a-strong-password', 'is_active' => true]);
        $guest = $this->seedCustomer('Guest Only', '0770 111 2222');

        $editor = $this->staffWith([Permission::CustomerView, Permission::CustomerUpdate, Permission::CustomerArchive], 'editor@alpha.test');

        expect(fn () => app(SetCustomerAccountStatus::class)($customer, false, $editor))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetCustomerAccountStatus::class)($guest, true, $owner))
            ->toThrow(ValidationException::class);

        expect(CustomerAccount::query()->sole()->is_active)->toBeTrue();

        // Archiving is not login management either: the archive Action turns
        // the login off, but restoring never turns it on.
        app(ArchiveCustomer::class)($customer, $owner);
        app(ArchiveCustomer::class)->restore($customer, $owner);

        expect(CustomerAccount::query()->sole()->is_active)->toBeFalse();
    });
});

it('adds and deletes notes with their author, and never audits the text', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();

        $panel = Livewire::actingAs($owner)
            ->test(NotesPanel::class, ['customer' => $customer->uuid])
            ->set('noteBody', 'Allergic to one brand of dye.')
            ->set('noteVisibility', NoteVisibility::ManagerOnly->value)
            ->call('addNote')
            ->assertHasNoErrors()
            ->assertSee('Allergic to one brand of dye.')
            ->assertSee($owner->name);

        $note = $customer->internalNotes()->sole();

        expect($note->visibility)->toBe(NoteVisibility::ManagerOnly)
            ->and($note->author_user_id)->toBe($owner->getKey());

        // A reader who may not manage notes sees internal ones only, and no delete.
        $reader = $this->staffWith([Permission::CustomerView, Permission::CustomerNoteView], 'reader@alpha.test');
        app(ManageCustomerNotes::class)->add($customer, 'Likes coffee.', $owner);

        Livewire::actingAs($reader)
            ->test(NotesPanel::class, ['customer' => $customer->uuid])
            ->assertSee('Likes coffee.')
            ->assertDontSee('Allergic to one brand of dye.')
            ->assertDontSeeHtml('wire:click="deleteNote(')
            ->call('deleteNote', $note->uuid)
            ->assertSet('noticeTone', 'danger');

        // Back to the owner: Livewire::actingAs() switched the signed-in user.
        $this->actingAs($owner);
        $panel->call('deleteNote', $note->uuid)->assertSet('noticeTone', 'success');

        expect($customer->internalNotes()->where('uuid', $note->uuid)->exists())->toBeFalse();

        $raw = json_encode(TenantAuditLog::query()->where('action', 'like', 'crm.customer_note.%')->get()->map->getAttributes(), JSON_THROW_ON_ERROR);

        expect(str_contains($raw, 'Allergic'))->toBeFalse()
            ->and(TenantAuditLog::query()->where('action', 'crm.customer_note.deleted')->count())->toBe(1);
    });
});

it('manages customer tags beside the list, and archives rather than deletes them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $customer = $this->seedCustomer();

        Livewire::actingAs($owner)->test(TagsDrawer::class)
            ->call('show')
            ->set('tagName', ['en' => 'VIP'])
            ->call('saveTag')
            ->assertHasNoErrors()
            ->assertDispatched('customer-tags-changed')
            ->assertSee('VIP');

        $tag = CustomerTag::query()->sole();
        $customer->tags()->attach($tag->getKey());

        Livewire::actingAs($owner)->test(TagsDrawer::class)
            ->call('show')
            ->call('rename', $tag->uuid)
            ->assertSet('tagName', ['en' => 'VIP'])
            ->set('tagName', ['en' => 'Gold client'])
            ->call('saveTag')
            ->call('archiveTag', $tag->uuid);

        expect($tag->refresh()->name->get('en'))->toBe('Gold client')
            ->and($tag->archived_at)->not->toBeNull()
            // Archived, not deleted: the customer keeps it.
            ->and($customer->tags()->count())->toBe(1)
            ->and(CustomerTag::query()->active()->count())->toBe(0);

        app(ManageCustomerTag::class)->restore($owner, $tag);

        Livewire::actingAs($owner)->test(CustomersPage::class)
            ->set('tag', $tag->uuid)
            ->assertSee('Sara Ahmed');

        $clerk = $this->staffWith([Permission::CustomerView], 'clerk@alpha.test');

        expect(fn () => app(ManageCustomerTag::class)->save($clerk, ['en' => 'Sneaky']))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageCustomerTag::class)->save($owner, ['en' => '']))
            ->toThrow(ValidationException::class);
    });
});

it('filters by visits and shows each customer\'s last visit', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $visitor = $this->seedCustomer('Visited Vera', '0750 222 3333');
        $this->seedCustomer('Never Nour', '0750 444 5555');
        $this->customerVisit($seed, $owner, $visitor);

        $query = app(CustomerQuery::class);

        expect(collect($query->paginate(['visited' => true], $owner)->items())->pluck('name')->all())->toBe(['Visited Vera'])
            ->and(collect($query->paginate(['visited' => false], $owner)->items())->pluck('name')->all())->toBe(['Never Nour'])
            ->and($query->lastVisits([(int) $visitor->getKey()], $owner))->toHaveKey((int) $visitor->getKey());

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->set('visited', 'yes')
            ->assertSee('Visited Vera')
            ->assertDontSee('Never Nour');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
