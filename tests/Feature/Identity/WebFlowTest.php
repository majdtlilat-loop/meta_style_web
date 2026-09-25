<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\CurrentSubscription;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\RegisterCenter;
use App\Livewire\Center\Dashboard;
use App\Livewire\Center\Staff;
use App\Modules\Employees\Domain\Models\Employee;
use App\View\Label;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Minimal web UI
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 3 Part N. Enough to prove the flows work through a
| browser-shaped path, not the beginnings of the design system.
|
*/

it('registers a center from the web form', function (): void {
    Livewire::test(RegisterCenter::class)
        ->set('centerName', 'Barbershop Alpha')
        ->set('centerSlug', 'barbershop-alpha')
        ->set('ownerName', 'Majd')
        ->set('email', 'owner@alpha.test')
        ->assertSet('phoneCountry', 'IQ')
        ->set('phone', '0750 123 4567')
        ->set('password', 'correct-horse-battery-staple')
        ->set('locale', 'en')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $registration = Registration::query()->firstOrFail();

    expect($registration->center_name)->toBe('Barbershop Alpha')
        ->and($registration->owner_phone)->toBe('+9647501234567')
        ->and($registration->status->value)->toBe('pending_verification');

    $this->runProvisioning($registration);
    $this->trackRegistrationDatabase($registration);
});

it('rejects a weak password on the web form', function (): void {
    Livewire::test(RegisterCenter::class)
        ->set('centerName', 'Barbershop Alpha')
        ->set('ownerName', 'Majd')
        ->set('email', 'owner@alpha.test')
        ->set('password', 'short')
        ->call('submit')
        ->assertHasErrors('password');

    expect(Registration::query()->count())->toBe(0);
});

it('signs a center owner in and remembers the center in the session', function (): void {
    $center = $this->registerCenter('Alpha', 'owner@alpha.test', 'correct-horse-battery-staple');
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        Livewire::test(Login::class)
            ->set('identifier', 'owner@alpha.test')
            ->set('password', 'correct-horse-battery-staple')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('center.dashboard'));
    });

    // Remembered server-side, in the signed session — never in a field the
    // browser can edit.
    expect(session()->get(StanclTenantResolver::SESSION_KEY))
        ->toBe($this->publicKeyOf($center['tenant']));
});

it('refuses a sign-in against the wrong center with one generic message', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    URL::defaults(['center' => $beta['registration']->requested_slug]);

    $this->asCenter($beta['tenant'], function (): void {
        Livewire::test(Login::class)
            ->set('identifier', 'owner@alpha.test')
            ->set('password', 'correct-horse-battery-staple')
            ->call('submit')
            ->assertHasErrors('identifier');
    });

    unset($alpha);
});

it('records a failed sign-in without storing the identifier', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        Livewire::test(Login::class)
            ->set('identifier', 'owner@alpha.test')
            ->set('password', 'definitely-wrong-password')
            ->call('submit')
            ->assertHasErrors('identifier');
    });

    $entry = $this->asCenter(
        $center['tenant'],
        fn () => TenantAuditLog::query()->where('action', 'identity.login.failed')->first(),
    );

    // A fingerprint is enough to correlate repeated attempts. The email itself
    // must not land in a durable log (docs/08-AUDIT-SECURITY.md §17).
    expect($entry)->not->toBeNull()
        ->and($entry->meta['reason'])->toBe('wrong_password')
        ->and($entry->meta['identifier_fingerprint'])->toBeString()
        ->and(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('owner@alpha.test');
});

it('shows the signed-in owner their center', function (): void {
    $center = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $owner = $this->ownerOf($center['tenant']);
    URL::defaults(['center' => $center['registration']->requested_slug]);

    session()->put(StanclTenantResolver::SESSION_KEY, $this->publicKeyOf($center['tenant']));

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $summary = app(CurrentSubscription::class)->summary();

        // A new center is on its trial; the page must say so in the reader's
        // language, never as the raw `trialing` status value.
        expect($summary?->status)->toBe(SubscriptionStatus::Trialing)
            ->and($summary?->trialDaysLeft)->toBeInt();

        Livewire::actingAs($owner)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Barbershop Alpha')
            ->assertSee(Label::for('subscription_status', 'trialing'))
            ->assertSee(trans_choice('manager_dashboard.plan.trial_days_left', (int) $summary?->trialDaysLeft, ['count' => $summary?->trialDaysLeft]))
            ->assertDontSee('trialing');
    });
});

it('adds a staff member from the web UI and shows the activation code once', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // The name is one field per enabled content language (the Staff form
        // uses the shared lang-tabs editor), so the primary locale is `name.en`.
        $component = Livewire::actingAs($owner)
            ->test(Staff::class)
            ->set('name.en', 'Sara')
            ->set('withLogin', true)
            ->set('email', 'sara@alpha.test')
            ->set('phone', '0770 123 0021')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('activationToken', fn (?string $token): bool => is_string($token) && $token !== '');

        $token = (string) $component->get('activationToken');

        expect(Employee::query()->count())->toBe(1)
            ->and(User::query()->where('email', 'sara@alpha.test')->firstOrFail()->password)->toBeNull();

        // Handed over as a full link to the guest activation page, never a bare
        // code nobody can use.
        $component->assertSee(route('activate', ['token' => $token]), false);
    });
});

it('hides staff management from a user without the permission', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        /** @var User $plain */
        $plain = User::query()->create(['name' => 'Plain', 'email' => 'p@x.test', 'is_active' => true]);

        Livewire::actingAs($plain)
            ->test(Staff::class)
            ->assertOk()
            ->assertSet('activationToken', null)
            // No create form, and no staff listed — the component asks for
            // permission rather than assuming a role.
            ->assertViewHas('canCreate', false)
            ->assertViewHas('employees', fn ($employees): bool => $employees->isEmpty());
    });
});

afterEach(function (): void {
    URL::defaults([]);
    $this->tearDownRegisteredCenters();
});
