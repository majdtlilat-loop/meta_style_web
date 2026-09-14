<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Center\Customers as CustomersPage;
use App\Livewire\Customer\SignIn as CustomerSignInPage;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Customer API, web UI, pagination and localization
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 5 §§16, 17, 23, 24, 25.
|
*/

function grantCustomerAccounts(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'customer_accounts'],
        ['mode' => 'grant', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('manages a customer end to end through the staff API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $created = $this->withHeaders($headers)->postJson('/api/v1/tenant/customers', [
        'name' => 'Sara Ahmed',
        'phone' => '0750 123 4567',
        'email' => 'sara@example.com',
        'preferred_locale' => 'ar',
        'marketing_opt_in' => true,
    ])->assertStatus(201)->json('data');

    expect($created['uuid'])->toBeString()
        // Uuids on the wire, never the auto-increment id.
        ->and($created)->not->toHaveKey('id')
        ->and($created['phone'])->toBe('+9647501234567')
        ->and($created['is_registered'])->toBeFalse()
        ->and($created['preferences']['marketing_opt_in'])->toBeTrue()
        ->and($created['preferences']['marketing_opt_in_at'])->toBeString();

    $this->withHeaders($headers)
        ->putJson("/api/v1/tenant/customers/{$created['uuid']}", [
            'name' => 'Sara A. Ahmed',
            'phone' => '0750 123 4567',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sara A. Ahmed');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/customers/{$created['uuid']}/notes", [
            'body' => 'Prefers a quiet appointment.',
            'visibility' => 'internal',
        ])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/customers/{$created['uuid']}")
        ->assertOk()
        ->assertJsonCount(1, 'data.notes');

    $this->withHeaders($headers)
        ->deleteJson("/api/v1/tenant/customers/{$created['uuid']}")
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    // Archived customers are out of the live list by default: showing them
    // would make the list wrong rather than complete.
    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers')
        ->assertOk()->assertJsonCount(0, 'data.customers');

    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers?archived=1')
        ->assertOk()->assertJsonCount(1, 'data.customers');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/customers/{$created['uuid']}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived', false);
});

it('paginates the customer list rather than loading the table', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        for ($i = 0; $i < 60; $i++) {
            $this->seedCustomer("Customer {$i}", '0750 000 '.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $response = $this->withHeaders($headers)->getJson('/api/v1/tenant/customers')->assertOk();

    // A center with fifteen thousand customers must not be able to load them
    // all by opening a screen (Phase 5 §16).
    expect($response->json('data.customers'))->toHaveCount(25)
        ->and($response->json('meta.total'))->toBe(60)
        ->and($response->json('meta.last_page'))->toBe(3);

    // And the cap is enforced, not merely suggested.
    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers?per_page=500')
        ->assertStatus(422);
});

it('renders a page of customers with their tags in a bounded number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /** @var CustomerTag $tag */
        $tag = CustomerTag::query()->create([
            'name' => TranslatedText::make('en', 'VIP'),
            'is_active' => true,
        ]);

        for ($i = 0; $i < 25; $i++) {
            $customer = $this->seedCustomer("Customer {$i}", '0750 100 '.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
            $customer->tags()->attach($tag->id);
            $customer->account()->create(['password' => 'a-strong-password', 'is_active' => true]);
        }
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $queries = 0;

    Event::listen(function (QueryExecuted $event) use (&$queries): void {
        if ($event->connectionName === 'tenant') {
            $queries++;
        }
    });

    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers')->assertOk();

    // Bounded by the number of RELATIONS, not by the number of customers.
    // Rendering tags and a registered badge naively is 1 + 2n queries.
    expect($queries)->toBeLessThan(20);
});

it('filters by search, account status and tag', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /** @var CustomerTag $vip */
        $vip = CustomerTag::query()->create([
            'name' => TranslatedText::make('en', 'VIP'),
            'is_active' => true,
        ]);

        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $sara->tags()->attach($vip->id);
        $sara->account()->create(['password' => 'a-strong-password', 'is_active' => true]);

        $this->seedCustomer('Ali Hassan', '0770 987 6543');
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers?search=Sara')
        ->assertOk()->assertJsonCount(1, 'data.customers');

    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers?registered=1')
        ->assertOk()->assertJsonPath('data.customers.0.name', 'Sara Ahmed');

    $this->withHeaders($headers)->getJson('/api/v1/tenant/customers?registered=0')
        ->assertOk()->assertJsonPath('data.customers.0.name', 'Ali Hassan');

    $tagUuid = $this->asCenter($center['tenant'], fn (): string => CustomerTag::query()->firstOrFail()->uuid);

    $this->withHeaders($headers)->getJson("/api/v1/tenant/customers?tag={$tagUuid}")
        ->assertOk()->assertJsonCount(1, 'data.customers');
});

it('exposes no internal note through the customer\'s own API', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    grantCustomerAccounts($center['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Customer::query()->firstOrFail()->addInternalNote(
            'Difficult customer — assign a senior stylist.',
            $owner,
            NoteVisibility::ManagerOnly,
        );
    });

    $body = (string) $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/customer/me')->assertOk()->getContent();

    // Notes are staff-only; the customer's own endpoint names its fields
    // explicitly and notes is not one of them.
    expect($body)->not->toContain('Difficult customer')
        ->not->toContain('senior stylist')
        // Their OWN contact details, in full: masking protects a person from
        // staff who do not need their details, not from themselves.
        ->toContain('+9647501234567');
});

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
*/

it('creates and archives a customer from the staff CRM screen', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->set('name', 'Sara Ahmed')
            ->set('phone', '0750 123 4567')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->firstOrFail();

        expect($customer->phone)->toBe('+9647501234567');

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('archive', $customer->uuid);

        expect($customer->refresh()->archived_at)->not->toBeNull();
    });
});

it('reports a duplicate phone in the form rather than creating a second record', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        Livewire::actingAs($owner)
            ->test(CustomersPage::class)
            ->call('create')
            ->set('name', 'Someone Else')
            // A different spelling of the same number.
            ->set('phone', '+964 750 123 4567')
            ->call('save')
            ->assertHasErrors('phone');

        expect(Customer::query()->count())->toBe(1);
    });
});

it('lets a customer register and sign in from the web', function (): void {
    $center = $this->registerCenter();

    grantCustomerAccounts($center['tenant']->id);

    $key = $this->publicKeyOf($center['tenant']);

    Livewire::test(CustomerSignInPage::class, ['center' => $key])
        ->assertSet('centerKey', $key)
        ->set('mode', 'register')
        ->set('phone', '0750 123 4567')
        ->set('password', 'a-strong-password')
        ->set('name', 'Sara')
        ->call('register')
        ->assertHasNoErrors()
        // Redirected to the tenant-bound account page, exactly as staff login
        // redirects to the center dashboard.
        ->assertRedirect(route('customer.account'))
        // The password never survives the request in component state.
        ->assertSet('password', '');

    $this->asCenter($center['tenant'], function (): void {
        expect(CustomerAccount::query()->count())->toBe(1)
            ->and(Customer::query()->firstOrFail()->name)->toBe('Sara');
    });

    // And the center is remembered in the session, which is what resolves the
    // tenant on every later request (ADR-030).
    expect(session(StanclTenantResolver::SESSION_KEY))->toBe($key);
});

it('gives one answer to a failed web sign-in', function (): void {
    $center = $this->registerCenter();

    grantCustomerAccounts($center['tenant']->id);

    Livewire::test(CustomerSignInPage::class, ['center' => $this->publicKeyOf($center['tenant'])])
        ->set('phone', '0790 000 0000')
        ->set('password', 'the-wrong-password')
        ->call('login')
        ->assertNoRedirect()
        ->assertSet('error', 'Those details do not match our records.');
});

it('tells a customer plainly when the center does not offer accounts', function (): void {
    $center = $this->registerCenter();

    // Revoked explicitly: the trial plan DOES grant `customer_accounts`, so
    // "the default" is on, not off.
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $center['tenant']->id, 'entitlement' => 'customer_accounts'],
        ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($center['tenant']->id);
    Livewire::test(CustomerSignInPage::class, ['center' => $this->publicKeyOf($center['tenant'])])
        ->set('mode', 'register')
        ->set('phone', '0750 123 4567')
        ->set('password', 'a-strong-password')
        ->set('name', 'Sara')
        ->call('register')
        ->assertNoRedirect()
        ->assertSet('error', 'This center does not offer customer accounts.');
});

it('keeps the electronic menu open to guests', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->publishMenu();
        $this->seedCatalog();
    });

    // Browsing the menu must never require a login (Phase 5 §24).
    $this->get('/m/'.$this->publicKeyOf($center['tenant']))
        ->assertOk()
        ->assertSee('Haircut');
});

/*
|--------------------------------------------------------------------------
| Localization
|--------------------------------------------------------------------------
*/

it('keeps a customer\'s preferred language, and falls back when it is disabled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        $customer = $this->seedCustomer();
        $customer->forceFill(['preferred_locale' => 'ckb'])->save();

        $presenter = app(CustomerPresenter::class);
        $owner = $this->ownerOf(app(TenantContext::class)->require());

        expect($presenter->detail($customer, $owner)['preferred_locale'])->toBe('ckb');

        // The center drops Kurdish. The customer is not stranded — the normal
        // tenant fallback applies (docs/07-LOCALIZATION.md §5).
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        expect($presenter->detail($customer, $owner)['preferred_locale'])->toBe('en');
    });
});

it('renders the CRM in Arabic with the right direction', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'ar');
        app()->setLocale('ar');

        $this->seedCustomer();

        $html = (string) Livewire::actingAs($owner)->test(CustomersPage::class)->html();

        // Direction comes from the language registry, never a hardcoded list.
        expect(app(LanguageRegistry::class)->direction('ar'))->toBe('rtl')
            ->and($html)->toContain('Sara Ahmed');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
